<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if ($user && !$user->is_active) {
            return response()->json([
                'error'       => 'Account deactivated',
                'message'     => 'This account has been deactivated. Please contact support if you believe this is an error.',
                'deactivated' => true,
            ], 403);
        }

        return $next($request);
    }
}