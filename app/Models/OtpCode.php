<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    // Max wrong-code guesses allowed against a single OTP before it's
    // locked out and a fresh code must be requested. Keeps a 6-digit code
    // from being brute-forceable within its 10-minute validity window.
    const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'email',
        'code',
        'expires_at',
        'used',
        'attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used'       => 'boolean',
    ];

    public function isValid(): bool
    {
        return !$this->used && $this->expires_at->isFuture();
    }

    public function isLocked(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }
}