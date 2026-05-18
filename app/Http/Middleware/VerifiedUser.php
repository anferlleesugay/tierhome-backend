<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifiedUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated'
            ], 401);
        }

        if ($user->verification_status !== 'verified') {
            return response()->json([
                'error' => 'Account not verified',
                'status' => $user->verification_status
            ], 403);
        }

        return $next($request);
    }
}