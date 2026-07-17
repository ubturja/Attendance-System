<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // RBAC alias — usage: middleware(['auth:sanctum', 'role:Admin'])
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Zombie-token gate: soft-deleted / inactive users cannot keep using Sanctum tokens.
        // Appended to the api group and ordered AFTER Authenticate so Bearer auth resolves first.
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
        $middleware->appendToPriorityList(
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
