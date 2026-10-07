<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\FaceSecure;
use App\Models\User;
use App\Services\FaceMatcher;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Registrasi 3 langkah dalam satu halaman (panel diganti lewat JS, request lewat fetch/JSON):
 *   1. Data akun   -> simpan sementara di session + kirim OTP
 *   2. Verifikasi email (OTP)
 *   3. Verifikasi wajah -> akun + face_secure baru dibuat di sini
 */
class RegisterController extends Controller
{
    public function __construct(
        private OtpService $otp,
        private FaceMatcher $faces,
    ) {}

    public function create(): View
    {
        $reg = session('register');

        // Kalau halaman di-refresh, lanjutkan dari langkah terakhir
        $step = ! $reg ? 1 : (empty($reg['email_verified']) ? 2 : 3);

        return view('auth.register', [
            'step'  => $step,
            'draft' => ['name' => $reg['name'] ?? '', 'email' => $reg['email'] ?? ''],
        ]);
    }

    // ---------- Step 1: Data akun ----------

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:3', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ], [
            'name.required'      => 'Nama lengkap wajib diisi.',
            'name.min'           => 'Nama lengkap minimal 3 karakter.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email belum valid.',
            'email.unique'       => 'Email ini sudah terdaftar.',
            'password.required'  => 'Password wajib diisi.',
            'password.min'       => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Password tidak sama.',
        ]);

        $email = Str::lower($data['email']);

        session(['register' => [
            'name'           => $data['name'],
            'email'          => $email,
            'password'       => Hash::make($data['password']), // jangan simpan plain password di session
            'email_verified' => false,
        ]]);

        try {
            $this->otp->send($email, $data['name']);
        } catch (\Throwable $e) {
            Log::error('Gagal kirim OTP: ' . $e->getMessage());

            return response()->json([
                'message' => 'Gagal mengirim kode ke email. Cek konfigurasi email server.',
                'errors'  => ['email' => ['Gagal mengirim kode ke email. Cek konfigurasi email server.']],
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'email'   => $email,
            'message' => 'Kode verifikasi sudah dikirim ke email Anda.',
        ]);
    }

    // ---------- Step 2: Verifikasi email ----------

    public function verifyEmail(Request $request): JsonResponse
    {
        $email = session('register.email');
        if (! $email) {
            return $this->restart();
        }

        // Sudah terverifikasi (misal user menekan "Kembali" dari langkah 3)
        if (session('register.email_verified')) {
            return response()->json(['ok' => true]);
        }

        $request->validate(['code' => ['required', 'digits:6']], [
            'code.required' => 'Masukkan kode verifikasi.',
            'code.digits'   => 'Kode verifikasi harus 6 digit angka.',
        ]);

        $result = $this->otp->verify($email, $request->input('code'));

        if ($result !== 'ok') {
            $message = [
                'invalid'   => 'Kode verifikasi salah.',
                'expired'   => 'Kode sudah kedaluwarsa. Silakan kirim ulang.',
                'too_many'  => 'Terlalu banyak percobaan. Silakan kirim ulang kode.',
                'not_found' => 'Kode tidak ditemukan. Silakan kirim ulang.',
            ][$result];

            return response()->json(['message' => $message, 'errors' => ['code' => [$message]]], 422);
        }

        session()->put('register.email_verified', true);

        return response()->json(['ok' => true]);
    }

    public function resendOtp(): JsonResponse
    {
        $email = session('register.email');
        if (! $email) {
            return $this->restart();
        }

        $wait = $this->otp->cooldownRemaining($email);
        if ($wait > 0) {
            return response()->json(['message' => "Tunggu {$wait} detik sebelum kirim ulang kode."], 429);
        }

        try {
            $this->otp->send($email, session('register.name'));
        } catch (\Throwable $e) {
            Log::error('Gagal kirim ulang OTP: ' . $e->getMessage());

            return response()->json(['message' => 'Gagal mengirim ulang kode. Coba beberapa saat lagi.'], 500);
        }

        // Kode baru = verifikasi harus diulang
        session()->put('register.email_verified', false);

        return response()->json(['ok' => true, 'message' => 'Kode verifikasi baru sudah dikirim ke email Anda.']);
    }

    // ---------- Step 3: Verifikasi wajah ----------

    public function storeFace(Request $request): JsonResponse
    {
        $reg = session('register');
        if (! $reg) {
            return $this->restart();
        }
        if (empty($reg['email_verified'])) {
            return response()->json(['message' => 'Verifikasi email terlebih dahulu.'], 422);
        }

        $descriptor = $this->faces->parse($request->input('face_descriptor'));
        if (! $descriptor) {
            return response()->json(['message' => 'Wajah tidak terdeteksi. Pastikan wajah terlihat jelas lalu coba lagi.'], 422);
        }

        if ($this->faces->isAlreadyRegistered($descriptor)) {
            return response()->json(['message' => 'Wajah ini sudah terdaftar pada akun lain.'], 422);
        }

        // Cegah race condition: email bisa saja sudah terdaftar sejak langkah 1
        if (User::where('email', $reg['email'])->exists()) {
            session()->forget('register');

            return $this->restart('Email ini sudah terdaftar. Gunakan email lain.');
        }

        try {
            DB::transaction(function () use ($reg, $descriptor, $request) {
                $user = User::create([
                    'name'              => $reg['name'],
                    'email'             => $reg['email'],
                    'password'          => $reg['password'],   // sudah di-hash; cast 'hashed' tidak meng-hash ulang
                    'email_verified_at' => now(),
                ]);

                FaceSecure::create([
                    'user_id'         => $user->id,
                    'face_descriptor' => $descriptor,
                    'image_path'      => $this->storeImage($request->input('face_image'), $user->id),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Registrasi gagal: ' . $e->getMessage());

            return response()->json(['message' => 'Terjadi kesalahan pada sistem. Silakan coba kembali.'], 500);
        }

        session()->forget('register');

        return response()->json(['ok' => true, 'redirect' => route('login')]);
    }

    private function restart(string $message = 'Sesi pendaftaran habis. Silakan isi data kembali.'): JsonResponse
    {
        return response()->json(['message' => $message, 'restart' => true], 409);
    }

    /**
     * Simpan foto (base64 JPEG) ke storage/app/private/faces. Tidak bisa diakses publik.
     */
    private function storeImage(?string $dataUrl, int $userId): ?string
    {
        if (! $dataUrl || ! preg_match('/^data:image\/jpeg;base64,/', $dataUrl)) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($binary === false || strlen($binary) > 2 * 1024 * 1024) {
            return null;
        }

        $path = "faces/{$userId}.jpg";
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
