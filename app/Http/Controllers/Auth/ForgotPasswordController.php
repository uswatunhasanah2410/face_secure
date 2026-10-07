<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Lupa password 3 langkah (1 halaman, request lewat fetch/JSON):
 *   1. Masukkan email      -> kirim OTP (kalau email terdaftar)
 *   2. Masukkan kode OTP
 *   3. Buat password baru
 *
 * Keamanan:
 * - Respons langkah 1 & 2 SAMA untuk email terdaftar maupun tidak (cegah user enumeration).
 * - Batas percobaan kode & jeda kirim ulang disimpan di session, jadi berlaku sama untuk semua email.
 * - Setelah password diganti, semua sesi login lain milik user tersebut diputus.
 */
class ForgotPasswordController extends Controller
{
    private const PURPOSE = 'reset';
    private const SESSION_KEY = 'password_reset';
    private const MAX_ATTEMPTS = 5;

    public function __construct(private OtpService $otp) {}

    public function create(): View
    {
        $reset = session(self::SESSION_KEY);
        $step = ! $reset ? 1 : (empty($reset['verified']) ? 2 : 3);

        return view('auth.forgot-password', [
            'step'  => $step,
            'email' => $reset['email'] ?? '',
        ]);
    }

    // ---------- Step 1: Email ----------

    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email belum valid.',
        ]);

        $email = Str::lower($data['email']);

        session([self::SESSION_KEY => [
            'email'    => $email,
            'verified' => false,
            'attempts' => 0,
            'sent_at'  => now()->timestamp,
        ]]);

        if (! $this->sendIfRegistered($email)) {
            return response()->json(['message' => 'Gagal mengirim kode ke email. Coba beberapa saat lagi.'], 500);
        }

        return response()->json([
            'ok'      => true,
            'email'   => $email,
            'message' => 'Jika email terdaftar, kode verifikasi sudah dikirim.',
        ]);
    }

    // ---------- Step 2: Kode OTP ----------

    public function verifyCode(Request $request): JsonResponse
    {
        $reset = session(self::SESSION_KEY);
        if (! $reset) {
            return $this->restart();
        }
        if (! empty($reset['verified'])) {
            return response()->json(['ok' => true]);
        }

        $request->validate(['code' => ['required', 'digits:6']], [
            'code.required' => 'Masukkan kode verifikasi.',
            'code.digits'   => 'Kode verifikasi harus 6 digit angka.',
        ]);

        if ($reset['attempts'] >= self::MAX_ATTEMPTS) {
            return $this->codeError('Terlalu banyak percobaan. Silakan kirim ulang kode.');
        }

        $result = $this->otp->verify($reset['email'], $request->input('code'), self::PURPOSE);

        if ($result !== 'ok') {
            session()->put(self::SESSION_KEY . '.attempts', $reset['attempts'] + 1);

            // Pesan disamakan supaya tidak ketahuan email mana yang terdaftar
            return $this->codeError(
                $reset['attempts'] + 1 >= self::MAX_ATTEMPTS || $result === 'too_many'
                    ? 'Terlalu banyak percobaan. Silakan kirim ulang kode.'
                    : 'Kode salah atau sudah kedaluwarsa. Coba lagi atau kirim ulang kode.'
            );
        }

        session()->put(self::SESSION_KEY . '.verified', true);

        return response()->json(['ok' => true]);
    }

    public function resend(): JsonResponse
    {
        $reset = session(self::SESSION_KEY);
        if (! $reset) {
            return $this->restart();
        }

        $wait = OtpService::RESEND_COOLDOWN_SECONDS - (now()->timestamp - $reset['sent_at']);
        if ($wait > 0) {
            return response()->json(['message' => "Tunggu {$wait} detik sebelum kirim ulang kode."], 429);
        }

        session()->put(self::SESSION_KEY, array_merge($reset, [
            'verified' => false,
            'attempts' => 0,
            'sent_at'  => now()->timestamp,
        ]));

        if (! $this->sendIfRegistered($reset['email'])) {
            return response()->json(['message' => 'Gagal mengirim ulang kode. Coba beberapa saat lagi.'], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Jika email terdaftar, kode baru sudah dikirim.']);
    }

    // ---------- Step 3: Password baru ----------

    public function resetPassword(Request $request): JsonResponse
    {
        $reset = session(self::SESSION_KEY);
        if (! $reset) {
            return $this->restart();
        }
        if (empty($reset['verified'])) {
            return response()->json(['message' => 'Verifikasi kode terlebih dahulu.'], 422);
        }

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'password.required'  => 'Password baru wajib diisi.',
            'password.min'       => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Password tidak sama.',
        ]);

        $user = User::where('email', $reset['email'])->first();
        if (! $user) {
            session()->forget(self::SESSION_KEY);

            return $this->restart();
        }

        if (Hash::check($request->input('password'), $user->password)) {
            $msg = 'Password baru tidak boleh sama dengan password lama.';

            return response()->json(['message' => $msg, 'errors' => ['password' => [$msg]]], 422);
        }

        $user->password = $request->input('password');   // otomatis di-hash (cast 'hashed')
        $user->setRememberToken(Str::random(60));          // batalkan "remember me" lama
        $user->save();

        // Putus semua sesi login user ini di perangkat lain
        $sessionTable = config('session.table', 'sessions');
        if (Schema::hasTable($sessionTable)) {
            DB::table($sessionTable)->where('user_id', $user->id)->delete();
        }

        // Buka blokir percobaan login password untuk email ini
        RateLimiter::clear($user->email . '|' . $request->ip());

        session()->forget(self::SESSION_KEY);
        Log::info("Password direset untuk user #{$user->id}");

        return response()->json(['ok' => true, 'redirect' => route('login')]);
    }

    // ---------- Helper ----------

    /**
     * Kirim OTP hanya kalau email terdaftar. Return false kalau pengiriman email gagal.
     * Jeda kirim ulang per email (di tabel otp_codes) mencegah spam walau session dibuat ulang.
     */
    private function sendIfRegistered(string $email): bool
    {
        $user = User::where('email', $email)->first();

        if (! $user || $this->otp->cooldownRemaining($email, self::PURPOSE) > 0) {
            return true;
        }

        try {
            $this->otp->send($email, $user->name, self::PURPOSE);
        } catch (\Throwable $e) {
            Log::error('Gagal kirim OTP reset password: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    private function codeError(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['code' => [$message]]], 422);
    }

    private function restart(string $message = 'Sesi reset password habis. Silakan masukkan email kembali.'): JsonResponse
    {
        return response()->json(['message' => $message, 'restart' => true], 409);
    }
}
