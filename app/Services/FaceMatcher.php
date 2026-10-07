<?php

namespace App\Services;

use App\Models\FaceSecure;

/**
 * Membandingkan face descriptor (128 angka dari face-api.js).
 * Makin kecil jarak Euclidean = makin mirip.
 *   < 0.40  -> sangat mirip (orang yang sama)
 *   < 0.50  -> threshold default
 *   > 0.60  -> biasanya orang berbeda
 */
class FaceMatcher
{
    public const DIMENSION = 128;
    public const THRESHOLD = 0.5;          // jarak maks agar dianggap orang yang sama saat login
    public const DUPLICATE_THRESHOLD = 0.4; // jarak untuk menolak wajah yang sudah dipakai akun lain

    public function threshold(): float
    {
        return self::THRESHOLD;
    }

    /**
     * Ubah input JSON jadi array 128 float. Return null kalau formatnya tidak valid.
     */
    public function parse(?string $json): ?array
    {
        if (! $json) {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data) || count($data) !== self::DIMENSION) {
            return null;
        }

        foreach ($data as $v) {
            if (! is_numeric($v) || ! is_finite((float) $v) || abs((float) $v) > 1) {
                return null;
            }
        }

        return array_map('floatval', array_values($data));
    }

    public function distance(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0; $i < self::DIMENSION; $i++) {
            $d = $a[$i] - $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    public function matches(array $a, array $b): bool
    {
        return $this->distance($a, $b) < $this->threshold();
    }

    /**
     * Cek apakah wajah ini sudah dipakai akun lain (cegah 1 wajah untuk banyak akun).
     */
    public function isAlreadyRegistered(array $descriptor): bool
    {
        foreach (FaceSecure::cursor() as $face) {
            if ($this->distance($descriptor, $face->face_descriptor) < self::DUPLICATE_THRESHOLD) {
                return true;
            }
        }

        return false;
    }
}
