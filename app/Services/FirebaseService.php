<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Illuminate\Support\Facades\Log;
use App\Models\DeviceToken;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        try {
            $credentialsPath = config('services.firebase.credentials');

            if ($credentialsPath && file_exists($credentialsPath)) {
                $factory = (new Factory())->withServiceAccount($credentialsPath);
                $this->messaging = $factory->createMessaging();
            }
        } catch (\Exception $e) {
            Log::warning('Firebase initialization failed: ' . $e->getMessage());
            $this->messaging = null;
        }
    }

    /**
     * Send a push notification to a specific user's registered device(s).
     * Silently does nothing if the user has no devices registered, or if Firebase isn't configured —
     * push is always a "nice to have" on top of in-app/email, never something that should break the app.
     */
    public function sendToUser($userId, string $title, string $body, ?string $link = null): void
    {
        if (!$this->messaging) return;

        $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->toArray();
        if (empty($tokens)) return;

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification(FirebaseNotification::create($title, $body))
                    ->withData($link ? ['link' => $link] : []);

                $this->messaging->send($message);

            } catch (\Exception $e) {
                Log::warning("Push notification failed for token {$token}: " . $e->getMessage());

                // If the token is no longer valid (app uninstalled, etc.), clean it up
                if (str_contains($e->getMessage(), 'registration-token-not-registered')
                    || str_contains($e->getMessage(), 'invalid-argument')) {
                    DeviceToken::where('token', $token)->delete();
                }
            }
        }
    }
}