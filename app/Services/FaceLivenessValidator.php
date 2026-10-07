<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Liveness & kualitas wajah (revisi 2, 3, 4, 5).
 *
 * Alur:
 *  1. issue()    : server membuat tantangan acak (kedip + gerakan acak, urutan acak), sekali pakai, ada batas waktu.
 *  2. Browser menjalankan tantangan dan mengirim hasil + sampel wajah beserta metriknya.
 *  3. validate() : server memeriksa ulang semuanya: nonce, urutan tantangan, waktu, nilai gerakan,
 *                  kualitas setiap sampel (utuh, lurus, mata terbuka) dan konsistensi antar sampel.
 */
class FaceLivenessValidator
{
    private const SESSION_PREFIX = 'face_challenge.';

    public function __construct(private FaceMatcher $faces) {}

    /** Buat tantangan baru untuk konteks 'register' atau 'login'. */
    public function issue(string $context): array
    {
        $L = config('face.liveness');

        $extras = collect($L['extra_pool'])->shuffle()->take(max(0, (int) $L['extra_steps']))->all();
        $steps = collect(['blink', ...$extras])->shuffle()->values()->all();

        $flash = config('face.flash.enabled') ? $this->flashColors((int) config('face.flash.count')) : null;

        $challenge = [
            'nonce'     => Str::random(40),
            'steps'     => $steps,
            'flash'     => $flash,
            'issued_at' => now()->getTimestampMs() / 1000,
        ];
        session([self::SESSION_PREFIX . $context => $challenge]);

        return [
            'nonce'      => $challenge['nonce'],
            'steps'      => $steps,
            'flash'      => $flash,
            'expires_in' => $L['challenge_ttl'],
            'samples'    => config("face.samples.{$context}"),
        ];
    }

    /**
     * Validasi hasil verifikasi dari browser.
     * $storedEye: skor mata normal saat registrasi (untuk login), null saat registrasi.
     *
     * Return ['ok' => true, 'samples' => [...descriptor], 'eye_baseline' => float]
     *     atau ['ok' => false, 'code' => ..., 'message' => ...]
     */
    public function validate(array $payload, string $context, ?float $storedEye = null): array
    {
        $Q = config('face.quality');
        $L = config('face.liveness');

        // ---- 1. Tantangan: ada, cocok, belum kedaluwarsa, sekali pakai ----
        $challenge = session()->pull(self::SESSION_PREFIX . $context);   // pull = langsung dihapus (sekali pakai)
        if (! $challenge || ! hash_equals($challenge['nonce'], (string) ($payload['nonce'] ?? ''))) {
            return $this->fail('challenge', 'Sesi verifikasi wajah tidak valid atau sudah dipakai. Silakan ulangi.');
        }
        $elapsed = now()->getTimestampMs() / 1000 - $challenge['issued_at'];
        if ($elapsed > $L['challenge_ttl']) {
            return $this->fail('expired', 'Waktu verifikasi wajah habis. Silakan ulangi.');
        }
        if ($elapsed * 1000 < $L['min_duration_ms']) {
            return $this->fail('too_fast', 'Proses verifikasi terlalu cepat dan tidak wajar. Silakan ulangi.');
        }

        // ---- 1b. Kamera virtual ditolak ----
        $camera = (string) ($payload['camera'] ?? '');
        $blocked = config('face.blocked_cameras');
        if ($blocked && preg_match('/' . $blocked . '/i', $camera)) {
            return $this->fail('camera', 'Kamera virtual terdeteksi. Gunakan kamera asli perangkat Anda.');
        }

        // ---- 2. Kondisi mata normal (revisi 5) ----
        $baseline = $payload['eye_baseline'] ?? null;
        if (! is_numeric($baseline) || $baseline < 0 || $baseline > $Q['eye_open_max']) {
            return $this->fail('eyes', 'Mata tidak terdeteksi terbuka dengan jelas. Buka mata Anda dan tatap kamera.');
        }
        $baseline = (float) $baseline;
        if ($storedEye !== null && $baseline > $storedEye + $L['blink_reopen'] + 0.10) {
            return $this->fail('eyes', 'Mata terdeteksi lebih tertutup dibanding saat pendaftaran. Buka mata Anda dan tatap kamera.');
        }

        // ---- 3. Liveness: semua tantangan dilakukan sesuai urutan & nilainya masuk akal (revisi 2) ----
        $steps = $payload['steps'] ?? [];
        if (! is_array($steps) || array_column($steps, 'type') !== $challenge['steps']) {
            return $this->fail('liveness', 'Tantangan liveness tidak lengkap atau urutannya tidak sesuai. Silakan ulangi.');
        }
        foreach ($steps as $step) {
            // Anti video rekaman: gerakan harus dimulai SETELAH instruksi muncul, dalam waktu reaksi manusia
            $onset = $step['onset_ms'] ?? null;
            if (! is_numeric($onset) || $onset < $L['react_min_ms'] || $onset > $L['react_max_ms']) {
                return $this->fail('timing', 'Waktu gerakan tidak sesuai dengan munculnya instruksi. Pastikan Anda hadir langsung (bukan video).');
            }
            $value = is_numeric($step['value'] ?? null) ? (float) $step['value'] : -1;
            $passed = match ($step['type']) {
                'blink'      => $value >= $L['blink_closed'] && $value - $baseline >= $L['blink_delta'] && $value <= 1,
                'turn_left', 'turn_right' => $value >= $L['turn_deg'] && $value <= 80,
                'open_mouth' => $value >= $L['mouth_open'] && $value <= 1,
                default      => false,
            };
            if (! $passed) {
                return $this->fail('liveness', 'Gerakan liveness tidak terdeteksi dengan benar. Pastikan Anda hadir langsung di depan kamera (bukan foto/video).');
            }
        }

        // ---- 3b. Pantulan warna layar pada wajah (anti video di layar HP) ----
        if ($challenge['flash']) {
            $F = config('face.flash');
            $flash = $payload['flash'] ?? null;
            if (! is_array($flash) || ($flash['colors'] ?? null) !== $challenge['flash']
                || ! is_numeric($flash['corr'] ?? null) || ! is_numeric($flash['amp'] ?? null)) {
                return $this->fail('replay', 'Pemeriksaan pantulan layar tidak lengkap. Silakan ulangi.');
            }
            if ($flash['corr'] < $F['min_corr'] || $flash['amp'] < $F['min_amp']) {
                return $this->fail('replay', 'Pantulan cahaya layar pada wajah tidak terdeteksi. Wajah kemungkinan ditampilkan dari layar/video.');
            }
        }

        // ---- 4. Sampel: jumlah cukup, descriptor valid, setiap sampel lolos cek kualitas (revisi 3, 4, 5) ----
        $need = array_sum(config("face.samples.{$context}"));
        $samples = $payload['samples'] ?? [];
        if (! is_array($samples) || count($samples) < $need || count($samples) > $need + 2) {
            return $this->fail('samples', 'Jumlah sampel wajah tidak sesuai. Silakan ulangi.');
        }

        $eyeLimit = min($Q['eye_open_max'], $baseline + $L['blink_reopen']);
        $descriptors = [];
        foreach ($samples as $s) {
            $d = $s['descriptor'] ?? null;
            if (! $this->faces->isValidDescriptor($d)) {
                return $this->fail('samples', 'Data wajah tidak valid. Silakan ulangi.');
            }
            if ($problem = $this->qualityProblem($s['metrics'] ?? [], $eyeLimit)) {
                return $problem;
            }
            $descriptors[] = array_map('floatval', $d);
        }

        // ---- 5. Semua sampel harus orang yang sama (tidak berganti orang/foto di tengah proses) ----
        if ($this->faces->maxPairwise($descriptors) > config('face.match.consistency')) {
            return $this->fail('inconsistent', 'Wajah yang terdeteksi berubah selama proses verifikasi. Pastikan hanya Anda di depan kamera.');
        }

        return ['ok' => true, 'samples' => $descriptors, 'eye_baseline' => round($baseline, 4)];
    }

    /** Cek kualitas 1 sampel (wajah utuh, lurus, mata terbuka). Null = lolos. */
    private function qualityProblem(array $m, float $eyeLimit): ?array
    {
        $Q = config('face.quality');
        $num = fn ($k) => isset($m[$k]) && is_numeric($m[$k]) ? (float) $m[$k] : null;

        // Revisi 3: wajah utuh
        if (($m['in_frame'] ?? false) !== true || $num('width') === null || $num('width') < $Q['min_width'] || $num('width') > $Q['max_width']) {
            return $this->fail('partial', 'Wajah tidak terdeteksi utuh. Pastikan seluruh wajah terlihat di kamera.');
        }
        if ($num('cover') === null || $num('cover') >= $Q['cover_max']) {
            return $this->fail('covered', 'Wajah tertutup (masker/kain). Lepaskan penutup wajah agar seluruh wajah terlihat.');
        }
        if ($num('score') === null || $num('score') < $Q['min_score']) {
            return $this->fail('unclear', 'Wajah kurang jelas atau sebagian tertutup. Pastikan wajah terlihat utuh dan jelas.');
        }
        // Revisi 4: sudut wajah
        if ($num('yaw') === null || abs($num('yaw')) > $Q['max_yaw']
            || $num('roll') === null || abs($num('roll')) > $Q['max_roll']
            || $num('pitch') === null || $num('pitch') < $Q['pitch_min'] || $num('pitch') > $Q['pitch_max']) {
            return $this->fail('angle', 'Posisi wajah tidak lurus menghadap kamera. Hadapkan wajah lurus ke depan.');
        }
        // Revisi 5: mata terbuka
        if ($num('blink') === null || $num('blink') < 0 || $num('blink') > $eyeLimit) {
            return $this->fail('eyes', 'Mata terdeteksi tertutup. Buka mata Anda dan tatap kamera.');
        }

        return null;
    }

    /** Urutan warna acak: tiap warna muncul, tidak ada warna yang sama berturut-turut. */
    private function flashColors(int $count): array
    {
        $base = ['red', 'green', 'blue'];
        do {
            $colors = [];
            for ($i = 0; $i < $count; $i++) {
                $choices = array_values(array_diff($base, [end($colors) ?: null]));
                $colors[] = $choices[random_int(0, count($choices) - 1)];
            }
        } while (count(array_unique($colors)) < 3);

        return $colors;
    }

    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
