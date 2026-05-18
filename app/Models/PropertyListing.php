<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyListing extends Model
{
    protected $fillable = [
        'landlord_id',
        'title',
        'description',
        'price',
        'address',
        'barangay',
        'property_type',
        'latitude',
        'longitude',
        'image',
        'status',
        'is_verified',
        'total_slots',
        'available_slots',
    ];

    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function amenities()
    {
        return $this->belongsToMany(
            Amenity::class, 
            'listing_amenities',
            'listing_id',
            'amenity_id'
        );
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'listing_id');
    }

    public function images()
    {
        return $this->hasMany(ListingImage::class, 'listing_id')->orderBy('order');
    }

    public function savedListings()
    {
        return $this->hasMany(SavedListing::class, 'listing_id');
    }
}