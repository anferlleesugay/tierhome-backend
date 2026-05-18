<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'tenant_id',
        'landlord_id',
        'listing_id',
    ];

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function listing()
    {
        return $this->belongsTo(PropertyListing::class, 'listing_id')->with('images');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    // ✅ All listing card messages — for landlord grouping
    public function listingCards()
    {
        return $this->hasMany(Message::class)
            ->where('message_type', 'listing_card');
    }

    // ✅ All listing_message bubbles (listing card + text combined)
    public function listingMessages()
    {
        return $this->hasMany(Message::class)
            ->where('message_type', 'listing_message')
            ->whereNotNull('listing_card_id');
    }
}