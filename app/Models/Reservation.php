<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    protected $fillable = [
        'tenant_id',
        'listing_id',
        'status',
        'move_in_date',
        'move_out_date',
        'notes',
        'terms_accepted_at',
        'cancellation_reason',
        'cancelled_by',
    ];

    protected $casts = [
        'move_in_date' => 'date',
        'move_out_date' => 'date',
        'terms_accepted_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function listing()
    {
        return $this->belongsTo(PropertyListing::class);
    }
}