<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * This app is a pure JSON API — the frontend is a decoupled SPA and there
 * is no server-rendered login page / named 'login' route anywhere in
 * routes/web.php. Laravel's default Authenticate::redirectTo() and
 * ValidationException handling both fall back to redirecting a browser to
 * a login page whenever the request doesn't explicitly say it wants JSON
 * (via the Accept header) — and since no such route exists here, that
 * redirect attempt throws RouteNotFoundException, which surfaces as a raw
 * 500 instead of a clean 401/422 JSON response.
 *
 * Our own frontend never hits this because axios sends
 * "Accept: application/json" automatically, but any other client that
 * doesn't set that header (curl, some webhook callers, some mobile HTTP
 * libraries) would see broken 500s instead of proper error responses.
 * Confirmed via the manual pentest pass (checklist 15j).
 *
 * Fix: force every request under /api/* to be treated as JSON-expecting,
 * regardless of what the client actually sent.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        return $next($request);
    }
}