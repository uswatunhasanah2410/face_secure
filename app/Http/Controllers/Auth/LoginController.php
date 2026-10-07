<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FaceLivenessValidator;
use App\Services\FaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
    private const PENDING_TTL_MINUTES = 5;     // batas waktu antara langkah 1 dan 2

    public function __construct(
        private FaceMatcher $faces,
        private FaceLivenessValidator $liveness,
    ) {}

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

    /** Tantangan liveness acak (kedip + gerakan) untuk verifikasi wajah login. */
    public function faceChallenge(): JsonResponse
    {
        $user = $this->pendingUser();
        if (! $user) {
            return $this->restart('Sesi login habis. Silakan masuk kembali.');
        }
        if ($user->faceSecure->isLocked()) {
            session()->forget('login_pending');

            return $this->restart($this->lockedMessage($user->faceSecure->locked_until), 423);
        }

        return response()->json($this->liveness->issue('login'));
    }

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

        // 1. Liveness + kualitas wajah (utuh, lurus, mata terbuka) + konsistensi sampel
        $result = $this->liveness->validate($request->all(), 'login', $face->eye_baseline);

        // 2. Cocokkan SEMUA sampel login dengan sampel tersimpan
        $match = $result['ok'] ? $this->faces->match($result['samples'], $face->samples()) : null;

        Log::info('Verifikasi wajah login', [
            'user_id' => $user->id,
            'liveness' => $result['ok'] ? 'ok' : $result['code'],
            'distance' => $match['worst'] ?? null,
        ]);

        if ($match && $match['ok']) {
            $face->update([
                'failed_attempts'  => 0,
                'locked_until'     => null,
                'last_verified_at' => now(),
            ]);

            session()->forget('login_pending');
            Auth::login($user);
            $request->session()->regenerate();

            return response()->json(['ok' => true, 'redirect' => route('home')] + $this->debugInfo($match));
        }

        // ---- Gagal: liveness/kualitas ditolak ATAU wajah tidak cocok -> dihitung sebagai percobaan gagal ----
        $max = (int) config('face.lockout.max_attempts');
        $minutes = (int) config('face.lockout.minutes');
        $face->increment('failed_attempts');

        if ($face->failed_attempts >= $max) {
            $face->update([
                'failed_attempts' => 0,
                'locked_until'    => now()->addMinutes($minutes),
            ]);
            session()->forget('login_pending');

            return $this->restart("Verifikasi wajah gagal {$max} kali. Akun dikunci {$minutes} menit.", 423);
        }

        $left = $max - $face->failed_attempts;
        $message = $result['ok']
            ? 'Wajah tidak cocok dengan data akun.'
            : $result['message'];

        return response()->json([
            'message' => "{$message} Sisa percobaan: {$left}.",
            'code'    => $result['ok'] ? 'mismatch' : $result['code'],
        ] + $this->debugInfo($match), 422);
    }

    /** Jarak wajah hanya ditampilkan saat APP_DEBUG=true (untuk kalibrasi), tidak di produksi. */
    private function debugInfo(?array $match): array
    {
        return config('app.debug') && $match
            ? ['debug' => ['distance' => $match['worst'], 'scores' => $match['scores'], 'threshold' => config('face.match.threshold')]]
            : [];
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
