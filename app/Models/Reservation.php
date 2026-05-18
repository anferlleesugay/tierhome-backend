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
        'notes',
    ];

    protected $casts = [
        'move_in_date' => 'date',
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