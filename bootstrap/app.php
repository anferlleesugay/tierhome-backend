<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);
        $middleware->alias([
            'verified' => \App\Http\Middleware\VerifiedUser::class,
            'admin'    => \App\Http\Middleware\AdminOnly::class,
            'active'   => \App\Http\Middleware\EnsureAccountActive::class,
        ]);
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackActivePlatform::class);

        // Fixes a real bug found via pentest (15j): unauthenticated/expired
        // requests without an explicit "Accept: application/json" header
        // were crashing with a 500 (Authenticate::redirectTo() tries to
        // route('login'), which doesn't exist in this SPA+API app) instead
        // of a clean 401/422 JSON response. See ForceJsonResponse for
        // the full explanation.
        $middleware->prependToGroup('api', \App\Http\Middleware\ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();