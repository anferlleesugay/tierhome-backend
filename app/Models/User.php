<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

use App\Models\IdentityVerification;
use App\Models\PropertyListing;
use App\Models\AffordabilityRanking;
use App\Models\SavedListing;
use App\Models\Reservation;
use App\Models\Conversation;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'last_name',
        'first_name',
        'middle_name',
        'phone_number',
        'email',
        'password',
        'role',
        'verification_status',
        'profile_picture',
        'verified_at',
        'is_active',
        'reactivation_requested_at',
        'report_frequency',
        'last_report_sent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'verified_at'       => 'datetime',
        ];
    }

    // =========================
    // AUTO GENERATE name
    // Runs before every create
    // Fixes: Field 'name' doesn't have
    // a default value error
    // =========================
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->name)) {
                $user->name = trim(
                    ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
                );
            }
        });

        static::updating(function ($user) {
            if (
                $user->isDirty('first_name') ||
                $user->isDirty('last_name')
            ) {
                $user->name = trim(
                    ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
                );
            }
        });
    }

    // =========================
    // Computed Full Name
    // =========================
    public function getFullNameAttribute()
    {
        return trim(
            $this->first_name . ' ' .
            ($this->middle_name
                ? $this->middle_name . ' '
                : '') .
            $this->last_name
        );
    }

    // =========================
    // JWT METHODS
    // =========================
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // =========================
    // IDENTITY VERIFICATION
    // =========================
    public function verification()
    {
        return $this->hasOne(IdentityVerification::class);
    }

    // =========================
    // LANDLORD RELATIONSHIPS
    // =========================
    public function listings()
    {
        return $this->hasMany(
            PropertyListing::class,
            'landlord_id'
        );
    }

    // =========================
    // TENANT RELATIONSHIPS
    // =========================
    public function rankings()
    {
        return $this->hasMany(
            AffordabilityRanking::class,
            'tenant_id'
        );
    }

    public function savedListings()
    {
        return $this->hasMany(
            SavedListing::class,
            'tenant_id'
        );
    }

    public function reservations()
    {
        return $this->hasMany(
            Reservation::class,
            'tenant_id'
        );
    }

    // =========================
    // MESSAGING
    // =========================
    public function tenantConversations()
    {
        return $this->hasMany(
            Conversation::class,
            'tenant_id'
        );
    }

    public function landlordConversations()
    {
        return $this->hasMany(
            Conversation::class,
            'landlord_id'
        );
    }
}