<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    public $timestamps = false; // only using created_at, set manually via useCurrent()

    protected $fillable = [
        'user_id',
        'search_type',
        'barangay',
        'budget',
        'property_type',
    ];

    /**
     * Log a search/interest event without ever breaking the calling request.
     * Fails silently — search logging should never block a real user action.
     */
    public static function log($type, $userId = null, $barangay = null, $budget = null, $propertyType = null)
    {
        try {
            self::create([
                'user_id'       => $userId,
                'search_type'   => $type,
                'barangay'      => $barangay,
                'budget'        => $budget,
                'property_type' => $propertyType,
            ]);
        } catch (\Exception $e) {
            \Log::warning('SearchLog::log failed: ' . $e->getMessage());
        }
    }
}