<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    protected $fillable = ['name', 'icon'];

    public function listings()
    {
        return $this->belongsToMany(PropertyListing::class, 'listing_amenities');
    }
}