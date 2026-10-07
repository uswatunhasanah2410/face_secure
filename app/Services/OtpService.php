<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public const LENGTH = 6;
    public const EXPIRE_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Buat kode baru, hapus kode lama, lalu kirim ke email.
     */
    public function send(string $email, string $name, string $purpose = 'register'): void
    {
        $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

        OtpCode::where('email', $email)->where('purpose', $purpose)->delete();

        OtpCode::create([
            'email'      => $email,
            'purpose'    => $purpose,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRE_MINUTES),
        ]);

        Mail::to($email)->send(new OtpMail($name, $code, self::EXPIRE_MINUTES, $purpose));
    }

    /**
     * Sisa detik sebelum boleh kirim ulang (0 = boleh).
     */
    public function cooldownRemaining(string $email, string $purpose = 'register'): int
    {
        $last = OtpCode::where('email', $email)->where('purpose', $purpose)->latest('id')->first();

        if (! $last) {
            return 0;
        }

        $elapsed = (int) $last->created_at->diffInSeconds(now(), true);

        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Cek kode. Return: 'ok' | 'invalid' | 'expired' | 'too_many' | 'not_found'
     */
    public function verify(string $email, string $code, string $purpose = 'register'): string
    {
        $otp = OtpCode::where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            return 'not_found';
        }
        if ($otp->isExpired()) {
            return 'expired';
        }
        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return 'too_many';
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return $otp->attempts >= self::MAX_ATTEMPTS ? 'too_many' : 'invalid';
        }

        $otp->update(['used_at' => now()]);

        return 'ok';
    }
}
