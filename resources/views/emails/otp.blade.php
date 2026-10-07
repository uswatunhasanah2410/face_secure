@php
    $isReset = ($purpose ?? 'register') === 'reset';
@endphp
<!DOCTYPE html>
<html lang="id">
<body style="margin:0;padding:0;background:#eef3ff;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef3ff;padding:30px 12px;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:16px;overflow:hidden;">
                <tr>
                    <td style="background:linear-gradient(180deg,#0b2a6f,#3d63c9);background-color:#0b2a6f;padding:26px;text-align:center;color:#fff;">
                        <div style="font-family:'Times New Roman',serif;font-size:28px;font-weight:bold;">
                            {{ $isReset ? 'Reset Password' : 'Verifikasi Email' }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 30px;color:#1d1d1f;font-size:15px;line-height:1.6;">
                        <p style="margin:0 0 14px;">Halo <strong>{{ $name }}</strong>,</p>
                        <p style="margin:0 0 20px;">
                            {{ $isReset
                                ? 'Kami menerima permintaan untuk mengatur ulang password akun Anda. Gunakan kode berikut:'
                                : 'Gunakan kode berikut untuk menyelesaikan pendaftaran akun Anda:' }}
                        </p>

                        <div style="text-align:center;margin:0 0 20px;">
                            <span style="display:inline-block;padding:14px 26px;background:#dfeafe;border-radius:12px;
                                         font-size:34px;font-weight:bold;letter-spacing:10px;color:#0b2a6f;">{{ $code }}</span>
                        </div>

                        <p style="margin:0 0 8px;">Kode berlaku selama <strong>{{ $expireMinutes }} menit</strong>.</p>
                        <p style="margin:0;color:#8a8a8a;font-size:13px;">
                            Jangan berikan kode ini kepada siapa pun.
                            {{ $isReset
                                ? 'Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tetap aman.'
                                : 'Jika Anda tidak merasa mendaftar, abaikan email ini.' }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
