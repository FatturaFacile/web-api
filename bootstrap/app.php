<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\LogSensitiveDataReadAccess;
use App\Http\Middleware\MarkDeprecated;
use App\Http\Middleware\NormalizeTenantInput;
use App\Http\Middleware\SecurityHeaders;
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
        $rawTrustedProxies = trim((string) env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'));
        $trustedProxies = ($rawTrustedProxies === '*' && in_array(env('APP_ENV'), ['local', 'testing'], true))
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $rawTrustedProxies)), fn (string $ip): bool => $ip !== '' && $ip !== '*'));
        $middleware->trustProxies(at: $trustedProxies);

        $middleware->prepend(NormalizeTenantInput::class);

        // Hardening headers (nosniff, no framing, no referrer, CSP, HSTS over HTTPS) on every response.
        $middleware->append(SecurityHeaders::class);

        $middleware->statefulApi();
        $middleware->throttleApi();

        // The tenant context and, after it, the two-factor requirement of that tenant.
        $middleware->group('tenant', [
            IdentifyTenant::class,
            EnsureTwoFactorEnrolled::class,
        ]);

        $middleware->alias([
            'module.access' => EnsureModuleAccess::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'locale' => SetLocale::class,
            'deprecated' => MarkDeprecated::class,
            'normalize.tenant' => NormalizeTenantInput::class,
            'privacy.sensitive.log' => LogSensitiveDataReadAccess::class,
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
