<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\NotificationMail;

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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Convenience helper to create a notification from anywhere in the app.
     * Creates the in-app notification AND sends an email — one call does both.
     *
     * Usage: Notification::notify($userId, 'reservation_approved', 'Reservation Approved', 'Your reservation for X was approved.', '/tenant/reservations');
     */
    public static function notify($userId, $type, $title, $message, $link = null)
    {
        if (!$userId) return null;

        $notification = self::create([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => $title,
            'message' => $message,
            'link'    => $link,
        ]);

        // ✅ Also send an email — wrapped in try/catch so a mail failure
        // never breaks the actual reservation/message/listing action itself
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

        return $notification;
    }
}