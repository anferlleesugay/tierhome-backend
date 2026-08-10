<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeactivationRequest extends Model
{
    protected $fillable = [
        'user_id',
        'reason',
        'status',
        'admin_note',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}