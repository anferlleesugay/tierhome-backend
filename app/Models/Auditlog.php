<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'admin_id',
        'action',
        'target_type',
        'target_id',
        'description',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Record an admin action. Wrapped in try/catch by callers is NOT required —
     * failures here should never block the actual action from completing, so
     * this method swallows its own errors and just logs them.
     */
    public static function record($adminId, $action, $targetType, $targetId, $description)
    {
        try {
            return self::create([
                'admin_id'    => $adminId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'description' => $description,
            ]);
        } catch (\Exception $e) {
            \Log::warning('AuditLog::record failed: ' . $e->getMessage());
            return null;
        }
    }
}