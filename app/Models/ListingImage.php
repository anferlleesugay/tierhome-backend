<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingImage extends Model
{
    protected $fillable = [
        'listing_id',
        'image_path',
        'is_cover',
        'order',
    ];

    public function listing()
    {
        return $this->belongsTo(PropertyListing::class, 'listing_id');
    }
}