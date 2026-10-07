<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceSecure extends Model
{
    protected $fillable = [
        'user_id', 'face_descriptor', 'image_path',
        'failed_attempts', 'locked_until', 'last_verified_at',
    ];

    protected $hidden = ['face_descriptor'];

    protected function casts(): array
    {
        return [
            'face_descriptor'  => 'encrypted:array',
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
}
