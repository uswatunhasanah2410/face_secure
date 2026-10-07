<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Login 2 langkah dalam satu halaman (request lewat fetch/JSON):
 *   1. Email + password  -> BELUM login, hanya simpan user id di session
 *   2. Verifikasi wajah  -> kalau cocok baru Auth::login()
 */
class LoginController extends Controller
{
    private const MAX_PASSWORD_ATTEMPTS = 5;   // per email+IP, per menit
    private const MAX_FACE_ATTEMPTS = 5;       // lalu akun dikunci sementara
    private const FACE_LOCK_MINUTES = 15;
    private const PENDING_TTL_MINUTES = 5;     // batas waktu antara langkah 1 dan 2

    public function __construct(private FaceMatcher $faces) {}

    public function create(): View
    {
        return view('auth.login', ['step' => $this->pendingUser() ? 2 : 1]);
    }

    // ---------- Step 1: Email & password ----------

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required'    => 'Email wajib diisi.',
            'email.email'       => 'Format email belum valid.',
            'password.required' => 'Password wajib diisi.',
        ]);

        $credentials['email'] = Str::lower($credentials['email']);
        $throttleKey = $credentials['email'] . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_PASSWORD_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->emailError("Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.", 429);
        }

        // validate() hanya mengecek, tidak membuat user login
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($throttleKey, 60);

            return $this->emailError('Email atau password salah.');
        }

        RateLimiter::clear($throttleKey);

        $user = User::with('faceSecure')->where('email', $credentials['email'])->first();

        if (! $user->faceSecure) {
            return $this->emailError('Akun ini belum memiliki data wajah. Hubungi admin.');
        }

        if ($user->faceSecure->isLocked()) {
            return $this->emailError($this->lockedMessage($user->faceSecure->locked_until), 423);
        }

        $request->session()->regenerate();
        session(['login_pending' => [
            'user_id'    => $user->id,
            'expires_at' => now()->addMinutes(self::PENDING_TTL_MINUTES)->timestamp,
        ]]);

        return response()->json(['ok' => true]);
    }

    // ---------- Step 2: Verifikasi wajah ----------

    public function verifyFace(Request $request): JsonResponse
    {
        $user = $this->pendingUser();
        if (! $user) {
            return $this->restart('Sesi login habis. Silakan masuk kembali.');
        }

        $face = $user->faceSecure;

        if ($face->isLocked()) {
            session()->forget('login_pending');

            return $this->restart($this->lockedMessage($face->locked_until), 423);
        }

        $descriptor = $this->faces->parse($request->input('face_descriptor'));

        if ($descriptor && $this->faces->matches($descriptor, $face->face_descriptor)) {
            $face->update([
                'failed_attempts'  => 0,
                'locked_until'     => null,
                'last_verified_at' => now(),
            ]);

            session()->forget('login_pending');
            Auth::login($user);
            $request->session()->regenerate();

            return response()->json(['ok' => true, 'redirect' => route('home')]);
        }

        // Gagal
        $face->increment('failed_attempts');

        if ($face->failed_attempts >= self::MAX_FACE_ATTEMPTS) {
            $face->update([
                'failed_attempts' => 0,
                'locked_until'    => now()->addMinutes(self::FACE_LOCK_MINUTES),
            ]);
            session()->forget('login_pending');

            return $this->restart(
                'Verifikasi wajah gagal ' . self::MAX_FACE_ATTEMPTS . ' kali. Akun dikunci ' . self::FACE_LOCK_MINUTES . ' menit.',
                423
            );
        }

        $left = self::MAX_FACE_ATTEMPTS - $face->failed_attempts;

        return response()->json([
            'message' => $descriptor
                ? "Wajah tidak cocok dengan data akun. Sisa percobaan: {$left}."
                : "Wajah tidak terdeteksi. Sisa percobaan: {$left}.",
        ], 422);
    }

    // ---------- Logout ----------

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }

    private function pendingUser(): ?User
    {
        $pending = session('login_pending');

        if (! $pending || $pending['expires_at'] < now()->timestamp) {
            session()->forget('login_pending');

            return null;
        }

        return User::with('faceSecure')->find($pending['user_id']);
    }

    private function emailError(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['email' => [$message]]], $status);
    }

    private function restart(string $message, int $status = 409): JsonResponse
    {
        return response()->json(['message' => $message, 'restart' => true], $status);
    }

    private function lockedMessage($until): string
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes($until, true)));

        return "Verifikasi wajah dikunci karena terlalu banyak gagal. Coba lagi dalam {$minutes} menit.";
    }
}
