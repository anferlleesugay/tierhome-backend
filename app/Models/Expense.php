<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'listing_id',
        'category',
        'amount',
        'description',
        'expense_date',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount'       => 'float',
    ];

    public function listing()
    {
        return $this->belongsTo(PropertyListing::class, 'listing_id');
    }
}