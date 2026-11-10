<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
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
    // Module routes, views and migrations are registered by L5Modular.
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // CSRF and sessions come from the default web group.
        $middleware->web(append: [SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Extra context on every reported exception.
        $exceptions->context(fn (): array => array_filter([
            'route' => request()?->route()?->getName(),
            'url' => app()->runningInConsole() ? null : request()?->fullUrl(),
        ]));

        // A database error while saving is logged, but the user sees a friendly message.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->isMethodSafe() || $request->expectsJson()) {
                return null;
            }

            return back()
                ->withInput()
                ->withErrors(['general' => __('Something went wrong while saving your changes. Please try again.')]);
        });
    })->create();
