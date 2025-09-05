<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.apikey' => \App\Http\Middleware\AuthenticateApiKey::class,
            'admin.token' => \App\Http\Middleware\EnsureAdminToken::class,
            'auth.user' => \App\Http\Middleware\EnsureAuthenticated::class,
            'tenant.access' => \App\Http\Middleware\EnsureTenantAccess::class,
            'auto.tenant.scope' => \App\Http\Middleware\AutoTenantScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
