<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdentityVerification extends Model
{
    use HasFactory;

    protected $table = 'identity_verifications';
    protected $fillable = [
        'user_id',
        'id_photo_path',
        'selfie_path',
        'rekognition_score',
        'verification_method',
        'face_id',
        'collection_id',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'verified_at',
        'indexed_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'indexed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'rekognition_score' => 'float',
    ];

    // Relationship to User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'approved')
            ->whereNotNull('verified_at')
            ->whereNotNull('indexed_at');
    }
}