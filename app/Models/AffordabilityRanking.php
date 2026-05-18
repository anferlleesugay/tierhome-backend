<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffordabilityRanking extends Model
{
    protected $fillable = [
        'tenant_id',
        'gross_income',
        'monthly_expenses',
        'utility_costs',
        'available_savings',
        'disposable_income',
        'recommended_max_rent',
        'results',
    ];

    protected $casts = [
        'results' => 'array',
        'gross_income' => 'float',
        'monthly_expenses' => 'float',
        'utility_costs' => 'float',
        'available_savings' => 'float',
        'disposable_income' => 'float',
        'recommended_max_rent' => 'float',
    ];

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }
}