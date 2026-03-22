<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantContext::class,
            'auth.token' => \App\Http\Middleware\EnsureApiTokenAuthenticated::class,
            'tenant.role' => \App\Http\Middleware\EnsureTenantRole::class,
            'system.admin' => \App\Http\Middleware\EnsureSystemAdmin::class,
            'widget.guard' => \App\Http\Middleware\EnsureWidgetRequestGuard::class,
            'widget.challenge' => \App\Http\Middleware\EnsureWidgetSessionChallenge::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
