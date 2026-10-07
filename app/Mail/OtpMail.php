<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email kode OTP. $purpose menentukan isi email:
 *   'register' -> verifikasi pendaftaran akun
 *   'reset'    -> atur ulang password
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $code,
        public int $expireMinutes,
        public string $purpose = 'register',
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->purpose === 'reset'
            ? 'Kode Reset Password Anda: '
            : 'Kode Verifikasi Akun Anda: ';

        return new Envelope(subject: $subject . $this->code);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}
