<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Logs\Http\Middleware\TrackHttpErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Modules (L5Modular) keep their own listeners and commands. Module routes,
    // views and migrations are registered by L5Modular's service provider.
    ->withEvents(discover: [__DIR__.'/../app/Modules/*/Listeners'])
    ->withCommands(glob(__DIR__.'/../app/Modules/*/Console/Commands', GLOB_ONLYDIR) ?: [])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // CSRF, cookie encryption and session handling come from the default
        // web group (no CSRF exceptions are configured).
        $middleware->web(append: [SecurityHeaders::class]);

        // Global (also runs for unmatched routes): records 4xx responses in the error log.
        $middleware->append(TrackHttpErrors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Extra context on every reported exception.
        $exceptions->context(fn (): array => array_filter([
            'route' => request()?->route()?->getName(),
            'url' => app()->runningInConsole() ? null : request()?->fullUrl(),
        ]));

        // A database failure while saving is still reported (logged with full
        // context) but the user gets a friendly message instead of a 500 page.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->isMethodSafe() || $request->expectsJson()) {
                return null;
            }

            return back()
                ->withInput()
                ->withErrors(['general' => __('Something went wrong while saving your changes. Please try again.')]);
        });
    })->create();
