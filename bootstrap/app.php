<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\MarkDeprecated;
use App\Http\Middleware\NormalizeTenantInput;
use App\Http\Middleware\SetLocale;
use Enterprise\MultiTenant\Http\Middleware\EnsureModuleAccess;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->prepend(NormalizeTenantInput::class);

        $middleware->statefulApi();
        $middleware->throttleApi();

        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'module.access' => EnsureModuleAccess::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'locale' => SetLocale::class,
            'deprecated' => MarkDeprecated::class,
            'normalize.tenant' => NormalizeTenantInput::class,
        ]);

        $middleware->priority([
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            Authenticate::class,
            IdentifyTenant::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
