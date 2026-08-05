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
        ]);
        $middleware->appendToGroup('api', \App\Http\Middleware\TrackActivePlatform::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();