<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * REGISTER a device token for push notifications (called by the mobile app on launch)
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'token'    => 'required|string|max:512',
            'platform' => 'nullable|string|in:android,ios',
        ]);

        $user = auth()->user();

        DeviceToken::updateOrCreate(
            ['user_id' => $user->id, 'token' => $validated['token']],
            ['platform' => $validated['platform'] ?? 'android']
        );

        return response()->json(['success' => true, 'message' => 'Device registered for push notifications']);
    }

    /**
     * UNREGISTER a device token (e.g. on logout)
     */
    public function unregister(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        DeviceToken::where('user_id', auth()->id())
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['success' => true]);
    }
}