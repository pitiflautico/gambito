<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Debug routes (solo en desarrollo)
            if (config('app.debug')) {
                Route::prefix('debug')
                    ->group(base_path('routes/debug.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\IsAdmin::class,
        ]);

        // Eximir rutas de verificación CSRF
        $middleware->validateCsrfTokens(except: [
            '/api/rooms/*/presence/check',  // Presence channel status check (guests need this)
            '/broadcasting/auth',             // Broadcasting auth (guests + Presence Channel)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
