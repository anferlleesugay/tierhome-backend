<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TrackActivePlatform
{
    /**
     * Updates the authenticated user's last_active_platform and last_active_at
     * on every request, based on the X-Platform header sent by the frontend.
     * The Capacitor app sends "app"; the regular website sends "web" (or nothing,
     * which defaults to "web").
     *
     * This is intentionally lightweight — a single unconditional update, wrapped
     * so it never blocks or breaks the actual request if something goes wrong.
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $user = auth('api')->user();

            if ($user) {
                $platform = $request->header('X-Platform', 'web');
                $platform = in_array($platform, ['app', 'web']) ? $platform : 'web';

                $user->forceFill([
                    'last_active_platform' => $platform,
                    'last_active_at'       => now(),
                ])->saveQuietly(); // saveQuietly = skip model events, this is just a lightweight timestamp bump
            }
        } catch (\Exception $e) {
            // Never let activity tracking break the actual request
        }

        return $next($request);
    }
}