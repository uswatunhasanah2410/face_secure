<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\FaceSecure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tes alur registrasi & login + 5 poin revisi verifikasi wajah:
 *  1. akurasi (orang lain ditolak)   2. liveness (anti foto)   3. wajah utuh
 *  4. sudut wajah                    5. kondisi mata
 * Jalankan: php artisan test --filter=FaceAuthFlowTest
 */
class FaceAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Tes memakai nilai default, tidak terpengaruh isi .env
        config(['face.liveness.extra_steps' => 2, 'face.liveness.challenge_ttl' => 120, 'face.liveness.total_timeout' => 90,
                'face.flash.enabled' => true, 'face.flash.min_corr' => 0.5, 'face.flash.min_amp' => 0.0015]);
    }

    /** Descriptor palsu 128 angka; $person berbeda = orang berbeda, $jitter = variasi kecil antar foto */
    private function vec(float $person, float $jitter = 0): array
    {
        $v = [];
        for ($i = 0; $i < 128; $i++) {
            $v[] = round(sin($i * 1.7 + $person) * 0.1 + sin($i * 3.1 + $jitter * 50) * $jitter, 6);
        }

        return $v;
    }

    private function goodMetrics(array $override = []): array
    {
        return array_merge([
            'score' => 0.97, 'yaw' => 3.5, 'pitch' => 6.0, 'roll' => 1.5, 'blink' => 0.06,
            'jaw' => 0.02, 'width' => 0.36, 'cover' => 6.5, 'in_frame' => true,
        ], $override);
    }

    /** Minta tantangan ke server lalu susun hasil verifikasi yang "lolos" untuk orang $person */
    private function facePayload(string $context, float $person, array $override = [], ?callable $mutate = null): array
    {
        $ch = $this->postJson("/{$context}/face/challenge")->assertOk()->json();
        $this->travel(4)->seconds();   // proses verifikasi butuh waktu wajar

        $count = $ch['samples']['before'] + $ch['samples']['after'];
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $samples[] = ['descriptor' => $this->vec($person, 0.002 * ($i + 1)), 'metrics' => $this->goodMetrics()];
        }
        $steps = array_map(fn ($t) => ['type' => $t, 'value' => match ($t) {
            'blink' => 0.72, 'turn_left', 'turn_right' => 27.0, 'open_mouth' => 0.6,
        }, 'onset_ms' => 750, 'ms' => 1400], $ch['steps']);

        $payload = array_merge([
            'nonce' => $ch['nonce'], 'samples' => $samples, 'eye_baseline' => 0.05,
            'steps' => $steps, 'duration_ms' => 4000, 'camera' => 'HD Webcam (04f2:b6d9)',
            'flash' => $ch['flash'] ? ['colors' => $ch['flash'], 'corr' => 0.86, 'amp' => 0.0042, 'frames' => 64] : null,
        ], $override);

        return $mutate ? $mutate($payload) : $payload;
    }

    private function registerUntilFace(string $email): void
    {
        Mail::fake();
        $this->postJson('/register', [
            'name' => 'Fadel', 'email' => $email,
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertOk();

        $code = null;
        Mail::assertSent(OtpMail::class, function ($m) use (&$code) { $code = $m->code; return true; });
        $this->postJson('/register/verify-email', ['code' => $code])->assertOk();
    }

    private function registerUser(string $email, float $person): User
    {
        $this->registerUntilFace($email);
        $this->postJson('/register/face', $this->facePayload('register', $person))->assertOk();
        $this->flushSession();

        return User::where('email', $email)->first();
    }

    private function loginPassword(string $email): void
    {
        $this->postJson('/login', ['email' => $email, 'password' => 'rahasia123'])->assertOk();
    }

    // ================= Alur utama =================

    public function test_full_flow_register_and_login(): void
    {
        $user = $this->registerUser('a@x.com', 1.0);
        $face = $user->faceSecure;
        $this->assertSame(5, $face->samples_count);
        $this->assertCount(5, $face->samples());
        $this->assertEqualsWithDelta(0.05, $face->eye_baseline, 0.0001);
        $this->assertTrue(Hash::check('rahasia123', $user->password));

        $this->loginPassword('a@x.com');
        $this->assertGuest();
        $this->postJson('/login/face', $this->facePayload('login', 1.0))->assertOk()->assertJson(['ok' => true]);
        $this->assertAuthenticated();
        $this->get('/home')->assertOk();
    }

    // ================= Revisi 1: akurasi =================

    public function test_revisi1_other_person_is_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        $this->postJson('/login/face', $this->facePayload('login', 2.2))
            ->assertStatus(422)->assertJsonPath('code', 'mismatch');
        $this->assertGuest();
    }

    public function test_revisi1_one_bad_sample_is_enough_to_reject(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        // 2 sampel orang yang benar + 1 sampel orang lain -> konsistensi gagal
        $payload = $this->facePayload('login', 1.0, [], function ($p) {
            $p['samples'][2]['descriptor'] = $this->vec(2.2);
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'inconsistent');
        $this->assertGuest();
    }

    public function test_revisi1_duplicate_face_cannot_register_second_account(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->registerUntilFace('b@x.com');
        $this->postJson('/register/face', $this->facePayload('register', 1.0))
            ->assertStatus(422)->assertJsonPath('code', 'duplicate');
        $this->assertSame(1, User::count());
    }

    // ================= Revisi 2: liveness (anti foto) =================

    public function test_revisi2_photo_cannot_blink(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        // Foto: mata tidak pernah terpejam -> skor kedip tetap rendah seperti mata terbuka
        $payload = $this->facePayload('login', 1.0, [], function ($p) {
            foreach ($p['steps'] as &$s) {
                if ($s['type'] === 'blink') { $s['value'] = 0.08; }
            }
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'liveness');
        $this->assertGuest();
    }

    public function test_revisi2_steps_must_match_random_challenge(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        // Tantangan hilang
        $this->postJson('/login/face', $this->facePayload('login', 1.0, ['steps' => []]))
            ->assertStatus(422)->assertJsonPath('code', 'liveness');
        // Urutan dibalik
        $payload = $this->facePayload('login', 1.0, [], fn ($p) => ['steps' => array_reverse($p['steps'])] + $p);
        if (count($payload['steps']) > 1 && $payload['steps'][0]['type'] !== $payload['steps'][1]['type']) {
            $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'liveness');
        }
        $this->assertGuest();
    }

    public function test_revisi2_challenge_is_single_use_and_time_limited(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        // Nonce palsu / dipakai ulang
        $payload = $this->facePayload('login', 1.0);
        $this->postJson('/login/face', ['nonce' => 'palsu'] + $payload)->assertStatus(422)->assertJsonPath('code', 'challenge');
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'challenge');

        // Terlalu cepat (otomatisasi): tantangan baru langsung dijawab tanpa jeda
        $fast = $this->facePayload('login', 1.0);
        $fast['nonce'] = $this->postJson('/login/face/challenge')->json('nonce');
        $this->postJson('/login/face', $fast)->assertStatus(422)->assertJsonPath('code', 'too_fast');

        // Kedaluwarsa
        $late = $this->facePayload('login', 1.0);
        $this->travel(120)->seconds();
        $this->postJson('/login/face', $late)->assertStatus(422)->assertJsonPath('code', 'expired');
    }

    public function test_revisi2_challenge_is_random(): void
    {
        $this->registerUntilFace('a@x.com');
        $seen = [];
        for ($i = 0; $i < 15; $i++) {   // batas rate limit: 20 per menit
            $seen[implode(',', $this->postJson('/register/face/challenge')->json('steps'))] = true;
        }
        $this->assertGreaterThan(2, count($seen), 'Tantangan harus bervariasi');
        foreach (array_keys($seen) as $combo) {
            $this->assertStringContainsString('blink', $combo);
        }
    }

    // ================= Revisi lanjutan: anti video di layar HP =================

    public function test_video_challenge_has_three_random_steps_and_flash_colors(): void
    {
        $this->registerUntilFace('a@x.com');
        $ch = $this->postJson('/register/face/challenge')->json();
        $this->assertCount(3, $ch['steps']);
        $this->assertContains('blink', $ch['steps']);
        $this->assertCount(3, array_unique($ch['steps']));
        $this->assertCount(6, $ch['flash']);
        $this->assertEqualsCanonicalizing(['red', 'green', 'blue'], array_values(array_unique($ch['flash'])));
        for ($i = 1; $i < 6; $i++) {
            $this->assertNotSame($ch['flash'][$i - 1], $ch['flash'][$i], 'warna tidak boleh sama berturut-turut');
        }
    }

    public function test_video_movement_before_instruction_is_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        $payload = $this->facePayload('login', 1.0, [], function ($p) {
            $p['steps'][1]['onset_ms'] = 120;   // bergerak 0,12 detik setelah instruksi: terlalu cepat untuk manusia
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'timing');
        $this->assertGuest();
    }

    public function test_video_on_phone_screen_without_reflection_is_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        // Layar HP: warna kulit tidak ikut berubah saat layar laptop berkedip
        $payload = $this->facePayload('login', 1.0, [], function ($p) {
            $p['flash']['corr'] = 0.08;
            $p['flash']['amp'] = 0.0004;
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'replay');

        // Urutan warna tidak sesuai yang diberikan server
        FaceSecure::query()->update(['failed_attempts' => 0]);
        $payload = $this->facePayload('login', 1.0, [], function ($p) {
            $p['flash']['colors'] = array_reverse($p['flash']['colors']);
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'replay');

        // Pemeriksaan pantulan dilewati
        $this->postJson('/login/face', $this->facePayload('login', 1.0, ['flash' => null]))
            ->assertStatus(422)->assertJsonPath('code', 'replay');
        $this->assertGuest();
    }

    public function test_virtual_camera_is_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        $this->postJson('/login/face', $this->facePayload('login', 1.0, ['camera' => 'OBS Virtual Camera']))
            ->assertStatus(422)->assertJsonPath('code', 'camera');
        $this->assertGuest();
    }

    // ================= Revisi 3: wajah utuh =================

    public function test_revisi3_partial_face_is_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        $cases = [
            ['in_frame' => false],              // sebagian wajah di luar frame
            ['width' => 0.10],                  // terlalu jauh / kecil
            ['score' => 0.62],                  // tertutup tangan/masker -> skor deteksi rendah
            ['width' => 0.90],                  // terlalu dekat / terpotong
            ['cover' => 34.0],                  // hidung, bibir, dagu tertutup masker
        ];
        foreach ($cases as $bad) {
            $payload = $this->facePayload('login', 1.0, [], function ($p) use ($bad) {
                $p['samples'][1]['metrics'] = $this->goodMetrics($bad);
                return $p;
            });
            $res = $this->postJson('/login/face', $payload)->assertStatus(422);
            $this->assertContains($res->json('code'), ['partial', 'unclear', 'covered'], json_encode($bad));
            FaceSecure::query()->update(['failed_attempts' => 0]);
        }
        $this->assertGuest();
    }

    // ================= Revisi 4: sudut wajah =================

    public function test_revisi4_side_or_tilted_face_is_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        foreach ([['yaw' => 25], ['yaw' => -18], ['roll' => 25], ['pitch' => 40], ['pitch' => -28]] as $bad) {
            FaceSecure::query()->update(['failed_attempts' => 0]);   // 5 kasus = 5 gagal -> jangan sampai terkunci di tes ini
            $payload = $this->facePayload('login', 1.0, [], function ($p) use ($bad) {
                $p['samples'][0]['metrics'] = $this->goodMetrics($bad);
                return $p;
            });
            $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'angle');
        }
        $this->assertGuest();
    }

    // ================= Revisi 5: kondisi mata =================

    public function test_revisi5_closed_eyes_are_rejected(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        // Sampel dengan mata tertutup
        $payload = $this->facePayload('login', 1.0, [], function ($p) {
            $p['samples'][0]['metrics'] = $this->goodMetrics(['blink' => 0.65]);
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'eyes');

        // Mata setengah terpejam sepanjang proses (jauh lebih tertutup dibanding saat registrasi)
        $payload = $this->facePayload('login', 1.0, ['eye_baseline' => 0.36], function ($p) {
            foreach ($p['samples'] as &$s) { $s['metrics']['blink'] = 0.36; }
            foreach ($p['steps'] as &$s) { if ($s['type'] === 'blink') { $s['value'] = 0.9; } }
            return $p;
        });
        $this->postJson('/login/face', $payload)->assertStatus(422)->assertJsonPath('code', 'eyes');
        $this->assertGuest();
    }

    // ================= Lain-lain =================

    public function test_failed_attempts_lock_the_account(): void
    {
        $this->registerUser('a@x.com', 1.0);
        $this->loginPassword('a@x.com');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/login/face', $this->facePayload('login', 2.2))->assertStatus(422);
        }
        $this->postJson('/login/face', $this->facePayload('login', 2.2))->assertStatus(423)->assertJson(['restart' => true]);
        $this->assertTrue(User::first()->faceSecure->isLocked());
        $this->postJson('/login', ['email' => 'a@x.com', 'password' => 'rahasia123'])->assertStatus(423);
    }

    public function test_session_guards(): void
    {
        $this->postJson('/register/face/challenge')->assertStatus(409);
        $this->postJson('/login/face/challenge')->assertStatus(409);
        $this->postJson('/login/face', [])->assertStatus(409);
    }

    public function test_old_single_descriptor_records_still_work(): void
    {
        $user = User::create(['name' => 'Lama', 'email' => 'lama@x.com', 'password' => 'rahasia123', 'email_verified_at' => now()]);
        FaceSecure::create(['user_id' => $user->id, 'face_descriptor' => $this->vec(1.0)]);   // format lama: 1 descriptor

        $this->loginPassword('lama@x.com');
        $this->postJson('/login/face', $this->facePayload('login', 1.0))->assertOk();
        $this->assertAuthenticated();
    }
}
