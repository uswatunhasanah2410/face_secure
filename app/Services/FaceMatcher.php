<?php

namespace App\Services;

use App\Models\FaceSecure;

/**
 * Membandingkan face descriptor (128 angka dari face-api.js).
 * Makin kecil jarak Euclidean = makin mirip. Batas diatur di config/face.php.
 *
 * Revisi 1 (akurasi):
 *  - Saat registrasi disimpan BEBERAPA sampel wajah (bukan 1 rata-rata).
 *  - Saat login, SETIAP sampel login harus dekat dengan sampel tersimpan
 *    (rata-rata k sampel tersimpan terdekat < threshold). Cukup 1 sampel gagal -> ditolak.
 */
class FaceMatcher
{
    public const DIMENSION = 128;

    public function distance(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0; $i < self::DIMENSION; $i++) {
            $d = $a[$i] - $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /** Validasi satu descriptor (array 128 float bernilai wajar). */
    public function isValidDescriptor(mixed $d): bool
    {
        if (! is_array($d) || count($d) !== self::DIMENSION || ! array_is_list($d)) {
            return false;
        }
        foreach ($d as $v) {
            if (! is_int($v) && ! is_float($v) || ! is_finite((float) $v) || abs((float) $v) > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Skor kecocokan 1 sampel terhadap kumpulan sampel tersimpan:
     * rata-rata jarak ke k sampel tersimpan terdekat.
     */
    public function scoreAgainst(array $probe, array $stored): float
    {
        $dists = array_map(fn ($s) => $this->distance($probe, $s), $stored);
        sort($dists);
        $k = max(1, min((int) config('face.match.nearest_k', 3), count($dists)));

        return array_sum(array_slice($dists, 0, $k)) / $k;
    }

    /**
     * Cocokkan semua sampel login terhadap sampel tersimpan.
     * Return ['ok' => bool, 'worst' => skor terburuk, 'scores' => [...]].
     */
    public function match(array $probes, array $stored): array
    {
        $scores = array_map(fn ($p) => round($this->scoreAgainst($p, $stored), 4), $probes);
        $worst = max($scores);

        return [
            'ok'     => $worst < (float) config('face.match.threshold'),
            'worst'  => $worst,
            'scores' => $scores,
        ];
    }

    /** Jarak terbesar antar sampel dalam satu sesi (harus kecil = orang yang sama). */
    public function maxPairwise(array $samples): float
    {
        $max = 0.0;
        $n = count($samples);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $max = max($max, $this->distance($samples[$i], $samples[$j]));
            }
        }

        return $max;
    }

    /** Wajah ini sudah dipakai akun lain? (cegah 1 wajah untuk banyak akun) */
    public function isAlreadyRegistered(array $samples, ?int $exceptUserId = null): bool
    {
        $limit = (float) config('face.match.duplicate');

        foreach (FaceSecure::query()->when($exceptUserId, fn ($q) => $q->where('user_id', '!=', $exceptUserId))->cursor() as $face) {
            $stored = $face->samples();
            if ($stored === []) {
                continue;
            }
            $scores = array_map(fn ($p) => $this->scoreAgainst($p, $stored), $samples);
            if (array_sum($scores) / count($scores) < $limit) {
                return true;
            }
        }

        return false;
    }
}
