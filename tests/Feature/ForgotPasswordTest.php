<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\FaceSecure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tes alur lupa password: email -> kode OTP -> password baru.
 * Jalankan: php artisan test --filter=ForgotPasswordTest
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::create([
            'name' => 'Fadel', 'email' => 'fadel@x.com',
            'password' => 'passlama123', 'email_verified_at' => now(),
        ]);
        FaceSecure::create(['user_id' => $user->id, 'face_descriptor' => array_fill(0, 128, 0.01)]);

        return $user;
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(OtpMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return $m->purpose === 'reset';
        });

        return $code;
    }

    public function test_full_reset_flow(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->get('/login')->assertSee(route('password.request'));
        $this->get('/forgot-password')->assertOk()->assertSee('Lupa')->assertSee('step: 1', false)
            ->assertDontSee('face-api.js');

        $this->postJson('/forgot-password', ['email' => 'FADEL@x.com'])
            ->assertOk()->assertJson(['ok' => true, 'email' => 'fadel@x.com']);
        $code = $this->sentCode();
        $this->get('/forgot-password')->assertSee('step: 2', false);

        // Belum verifikasi -> tidak boleh ganti password
        $this->postJson('/forgot-password/reset', ['password' => 'passbaru123', 'password_confirmation' => 'passbaru123'])
            ->assertStatus(422);

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/forgot-password/verify', ['code' => $wrong])->assertStatus(422);
        $this->postJson('/forgot-password/verify', ['code' => $code])->assertOk();
        $this->get('/forgot-password')->assertSee('step: 3', false);

        // Password sama dengan yang lama ditolak
        $this->postJson('/forgot-password/reset', ['password' => 'passlama123', 'password_confirmation' => 'passlama123'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Password baru tidak boleh sama dengan password lama.');
        // Konfirmasi tidak sama
        $this->postJson('/forgot-password/reset', ['password' => 'passbaru123', 'password_confirmation' => 'beda12345'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // Simulasi sesi login lain milik user ini (di perangkat lain)
        DB::table('sessions')->insert(['id' => 'sesi-lain', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $oldRemember = $user->remember_token;

        $this->postJson('/forgot-password/reset', ['password' => 'passbaru123', 'password_confirmation' => 'passbaru123'])
            ->assertOk()->assertJson(['ok' => true, 'redirect' => route('login')]);

        $user->refresh();
        $this->assertTrue(Hash::check('passbaru123', $user->password));
        $this->assertNotEquals($oldRemember, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('id', 'sesi-lain')->count());
        $this->get('/forgot-password')->assertSee('step: 1', false);

        // Login: password lama gagal, password baru lolos ke langkah wajah
        $this->postJson('/login', ['email' => 'fadel@x.com', 'password' => 'passlama123'])->assertStatus(422);
        $this->postJson('/login', ['email' => 'fadel@x.com', 'password' => 'passbaru123'])->assertOk();

        // Kode yang sama tidak bisa dipakai lagi
        $this->flushSession();
        $this->postJson('/forgot-password', ['email' => 'fadel@x.com'])->assertOk();
        $this->postJson('/forgot-password/verify', ['code' => $code])->assertStatus(422);
    }

    public function test_unknown_email_gets_identical_response_and_no_mail(): void
    {
        Mail::fake();
        $this->makeUser();

        $known = $this->postJson('/forgot-password', ['email' => 'fadel@x.com'])->json();
        $this->flushSession();
        $unknown = $this->postJson('/forgot-password', ['email' => 'tidakada@x.com'])->json();

        $this->assertSame($known['message'], $unknown['message']);
        Mail::assertSent(OtpMail::class, 1);

        // Pesan kode salah juga sama dengan email terdaftar
        $this->postJson('/forgot-password/verify', ['code' => '123456'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Kode salah atau sudah kedaluwarsa. Coba lagi atau kirim ulang kode.');
    }

    public function test_attempt_limit_resend_cooldown_and_session_guard(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->postJson('/forgot-password/verify', ['code' => '123456'])->assertStatus(409)->assertJson(['restart' => true]);
        $this->postJson('/forgot-password/reset', ['password' => 'x'])->assertStatus(409);

        $this->postJson('/forgot-password', ['email' => 'fadel@x.com'])->assertOk();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/forgot-password/verify', ['code' => $wrong])->assertStatus(422);
        }
        $this->postJson('/forgot-password/verify', ['code' => $wrong])
            ->assertJsonPath('errors.code.0', 'Terlalu banyak percobaan. Silakan kirim ulang kode.');
        // Kode benar pun ditolak setelah batas tercapai
        $this->postJson('/forgot-password/verify', ['code' => $code])->assertStatus(422);

        $this->postJson('/forgot-password/resend')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/forgot-password/resend')->assertOk();
        Mail::assertSent(OtpMail::class, 2);
    }
}
