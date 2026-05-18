<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmenityRequest extends Model
{
    protected $fillable = [
        'landlord_id',
        'name',
        'icon',
        'status',
        'admin_note',
    ];

    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }
}