<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\NotificationMail;
use App\Services\FirebaseService;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'link',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * Notification types considered "high-stakes" — infrequent, consequential events
     * where a permanent email record is valuable even for users who also get push.
     */
    protected static $highStakesTypes = [
        'reservation_approved',
        'reservation_rejected',
        'reservation_cancelled',
        'reservation_paid',
        'listing_approved',
        'listing_rejected',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Convenience helper to create a notification from anywhere in the app.
     * Handles in-app notification + push + email, following this logic:
     *
     * - User has a registered device (app installed):
     *     - High-stakes event (approvals, payments, refunds) → push AND email
     *     - Frequent/low-stakes event (messages, new requests) → push ONLY
     * - User has NO registered device (web-only):
     *     - Always email, regardless of event type — it's their only off-site channel
     */
    public static function notify($userId, $type, $title, $message, $link = null)
    {
        if (!$userId) return null;

        $notification = self::create([
            'user_id' => $userId,
            'type'    => $type,
            'link'    => $link,
            'title'   => $title,
            'message' => $message,
        ]);

        $hasDevice = DeviceToken::where('user_id', $userId)->exists();
        $isHighStakes = in_array($type, self::$highStakesTypes);

        // ✅ Push — sent whenever a device is registered, for every event type
        if ($hasDevice) {
            try {
                app(FirebaseService::class)->sendToUser($userId, $title, $message, $link);
            } catch (\Exception $e) {
                Log::warning('Failed to send push notification: ' . $e->getMessage());
            }
        }

        // ✅ Email — sent if the user has no device (web-only), OR the event is high-stakes
        $shouldEmail = !$hasDevice || $isHighStakes;

        if ($shouldEmail) {
            try {
                $user = User::find($userId);
                if ($user && $user->email) {
                    Mail::to($user->email)->send(
                        new NotificationMail($title, $message, $link, $user->first_name ?? 'there')
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Failed to send notification email: ' . $e->getMessage());
            }
        }

        return $notification;
    }
}