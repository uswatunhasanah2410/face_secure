<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceSecure extends Model
{
    protected $fillable = [
        'user_id', 'face_descriptor', 'eye_baseline', 'samples_count', 'image_path',
        'failed_attempts', 'locked_until', 'last_verified_at',
    ];

    protected $hidden = ['face_descriptor'];

    protected function casts(): array
    {
        return [
            'face_descriptor'  => 'encrypted:array',
            'eye_baseline'     => 'float',
            'locked_until'     => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Semua sampel descriptor tersimpan (array of array 128 float).
     * Data lama (versi sebelum revisi) hanya 1 descriptor -> dibungkus jadi 1 sampel.
     */
    public function samples(): array
    {
        $data = $this->face_descriptor ?? [];

        if ($data !== [] && is_numeric($data[0] ?? null)) {
            return [$data];
        }

        return $data;
    }
}
