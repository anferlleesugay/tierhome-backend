<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\NotificationMail;
use App\Services\FirebaseService;
use Carbon\Carbon;

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
     * How recently a user must have made a request on a platform to be
     * considered "currently active" there. Matches common practice
     * (e.g. Facebook-style presence windows).
     */
    const ACTIVE_WINDOW_MINUTES = 5;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Convenience helper to create a notification from anywhere in the app.
     * Routes between push and email based on which platform the user is
     * CURRENTLY active on, not just whether they have the app installed:
     *
     * - Active on the app right now (within the active window)   → push only
     * - Active on the web right now (within the active window)   → email only (in-app bell already covers on-site visibility)
     * - Not recently active anywhere within the window            → we genuinely don't know which channel
     *                                                                will reach them, so ALWAYS send email
     *                                                                (guaranteed-reach channel), plus push too
     *                                                                if a device happens to be registered.
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

        $user = User::find($userId);
        if (!$user) return $notification;

        $isRecentlyActive = $user->last_active_at
            && Carbon::parse($user->last_active_at)->diffInMinutes(now()) <= self::ACTIVE_WINDOW_MINUTES;

        $hasDevice = DeviceToken::where('user_id', $userId)->exists();

        $sendPush  = false;
        $sendEmail = false;

        if ($isRecentlyActive && $user->last_active_platform === 'app') {
            // Currently using the app — push only
            $sendPush = true;

        } elseif ($isRecentlyActive && $user->last_active_platform === 'web') {
            // Currently using the website — email only (in-app bell already visible to them on-site)
            $sendEmail = true;

        } else {
            // Not recently active anywhere within the window — we genuinely don't know
            // which channel will actually reach them, so cover both instead of gambling
            // on just one and risking a missed notification (as happened before this fix).
            $sendEmail = true;
            if ($hasDevice) {
                $sendPush = true;
            }
        }

        if ($sendPush) {
            try {
                app(FirebaseService::class)->sendToUser($userId, $title, $message, $link);
            } catch (\Exception $e) {
                Log::warning('Failed to send push notification: ' . $e->getMessage());
            }
        }

        if ($sendEmail) {
            try {
                if ($user->email) {
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