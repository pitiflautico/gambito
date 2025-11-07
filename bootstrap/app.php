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
        // Manejar error 419 CSRF token expirado para invitados
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            \Log::warning("CSRF token mismatch detected", [
                'route' => $request->route()?->getName(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);
            
            // Si es la ruta de storeGuestName, redirigir con mensaje amigable
            if ($request->routeIs('rooms.storeGuestName')) {
                $code = $request->route('code');
                $request->session()->regenerateToken();
                \Log::info("Regenerating CSRF token and redirecting guest-name", ['code' => $code]);
                return redirect()->route('rooms.guestName', ['code' => $code])
                    ->withErrors(['error' => 'Tu sesión expiró. Por favor, intenta nuevamente ingresando tu nombre.'])
                    ->withInput($request->only('player_name'));
            }
            
            // Para otras rutas, usar el comportamiento por defecto
            return null;
        });
    })->create();
