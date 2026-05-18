<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedListing extends Model
{
    protected $table = 'saved_listings';

    public $timestamps = false; // 🔥 FIX

    protected $fillable = [
        'tenant_id',
        'listing_id'
    ];

    public function listing()
    {
        return $this->belongsTo(\App\Models\PropertyListing::class, 'listing_id');
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\User::class, 'tenant_id');
    }
}