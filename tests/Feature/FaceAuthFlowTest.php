<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tes alur lengkap: registrasi (data -> OTP -> wajah) dan login (password -> wajah).
 * Jalankan: php artisan test --filter=FaceAuthFlowTest
 */
class FaceAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Descriptor palsu 128 angka; base berbeda = "orang" berbeda */
    private function desc(float $base): string
    {
        $v = [];
        for ($i = 0; $i < 128; $i++) {
            $v[] = round(sin($i + $base) * 0.1, 6);
        }

        return json_encode($v);
    }

    private function registerUntilFace(string $email): string
    {
        Mail::fake();
        $this->postJson('/register', [
            'name' => 'Fadel', 'email' => $email,
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertOk()->assertJson(['ok' => true, 'email' => $email]);

        $code = null;
        Mail::assertSent(OtpMail::class, function ($m) use (&$code) { $code = $m->code; return true; });

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/register/verify-email', ['code' => $wrong])->assertStatus(422)->assertJsonPath('errors.code.0', 'Kode verifikasi salah.');
        $this->postJson('/register/verify-email', ['code' => $code])->assertOk();

        return $code;
    }

    public function test_full_flow(): void
    {
        $this->get('/register')->assertOk()->assertSee('Buat Akun')->assertSee('step: 1', false);

        $this->registerUntilFace('a@x.com');
        $this->get('/register')->assertOk()->assertSee('step: 3', false);

        $this->postJson('/register/face', [
            'face_descriptor' => $this->desc(0),
            'face_image' => 'data:image/jpeg;base64,' . base64_encode('x'),
        ])->assertOk()->assertJson(['ok' => true, 'redirect' => route('login')]);

        $user = User::first();
        $this->assertNotNull($user->faceSecure);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
        $this->get('/register')->assertSee('step: 1', false);

        // Login: password salah
        $this->postJson('/login', ['email' => 'a@x.com', 'password' => 'salah123'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Email atau password salah.');

        // Password benar -> belum login, lanjut wajah
        $this->postJson('/login', ['email' => 'A@X.com', 'password' => 'rahasia123'])->assertOk();
        $this->assertGuest();
        $this->get('/login')->assertSee('step: 2', false);
        $this->get('/home')->assertRedirect('/login');

        // Wajah orang lain
        $this->postJson('/login/face', ['face_descriptor' => $this->desc(2)])
            ->assertStatus(422)->assertJsonPath('message', 'Wajah tidak cocok dengan data akun. Sisa percobaan: 4.');
        $this->assertGuest();

        // Wajah sendiri (sedikit berbeda karena noise kamera)
        $this->postJson('/login/face', ['face_descriptor' => $this->desc(0.01)])
            ->assertOk()->assertJson(['ok' => true, 'redirect' => route('home')]);
        $this->assertAuthenticated();
        $this->get('/home')->assertOk()->assertSee('Selamat Datang Kembali')->assertSee('a@x.com');

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_duplicate_face_is_rejected(): void
    {
        $this->registerUntilFace('a@x.com');
        $this->postJson('/register/face', ['face_descriptor' => $this->desc(0)])->assertOk();

        $this->flushSession();
        $this->registerUntilFace('b@x.com');
        $this->postJson('/register/face', ['face_descriptor' => $this->desc(0.005)])
            ->assertStatus(422)->assertJsonPath('message', 'Wajah ini sudah terdaftar pada akun lain.');
        $this->assertSame(1, User::count());

        // Data descriptor rusak
        $this->postJson('/register/face', ['face_descriptor' => '[1,2,3]'])->assertStatus(422);
    }

    public function test_face_lockout_after_five_failures(): void
    {
        $this->registerUntilFace('a@x.com');
        $this->postJson('/register/face', ['face_descriptor' => $this->desc(0)])->assertOk();

        $this->postJson('/login', ['email' => 'a@x.com', 'password' => 'rahasia123'])->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/login/face', ['face_descriptor' => $this->desc(3)])->assertStatus(422);
        }
        $this->postJson('/login/face', ['face_descriptor' => $this->desc(3)])
            ->assertStatus(423)->assertJson(['restart' => true]);

        $this->assertTrue(User::first()->faceSecure->isLocked());
        $this->postJson('/login', ['email' => 'a@x.com', 'password' => 'rahasia123'])->assertStatus(423);
        $this->assertGuest();
    }

    public function test_session_guards_and_resend_cooldown(): void
    {
        $this->postJson('/register/verify-email', ['code' => '123456'])->assertStatus(409)->assertJson(['restart' => true]);
        $this->postJson('/register/face', ['face_descriptor' => $this->desc(0)])->assertStatus(409);
        $this->postJson('/login/face', ['face_descriptor' => $this->desc(0)])->assertStatus(409);

        Mail::fake();
        $this->postJson('/register', ['name' => 'Fadel', 'email' => 'c@x.com', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])->assertOk();
        $this->get('/register')->assertSee('step: 2', false)->assertSee('c@x.com');
        $this->postJson('/register/face', ['face_descriptor' => $this->desc(0)])->assertStatus(422);

        $this->postJson('/register/resend-code')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/register/resend-code')->assertOk();
        Mail::assertSent(OtpMail::class, 2);

        // Validasi server
        $this->postJson('/register', ['name' => 'F', 'email' => 'bukan-email', 'password' => '123', 'password_confirmation' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);
    }
}
