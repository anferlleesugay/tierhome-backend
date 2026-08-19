<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    protected $fillable = [
        'listing_id',
        'tenant_id',
        'title',
        'description',
        'category',
        'status',
        'landlord_note',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function listing()
    {
        return $this->belongsTo(PropertyListing::class, 'listing_id');
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }
}