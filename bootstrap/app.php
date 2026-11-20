<?php

use App\Enums\Auth\AuthProblem;
use App\Exceptions\SodDocumentConflictException;
use App\Http\Middleware\AuditSensitiveAccess;
use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureAuditPermission;
use App\Http\Middleware\EnsureFeatureFlagEnabled;
use App\Http\Middleware\EnsureHttpsFrontendRequestsAreStateful;
use App\Http\Middleware\EnsureMobileDeviceActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsurePortalSession;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\LogSensitiveDataReadAccess;
use App\Http\Middleware\MarkDeprecated;
use App\Http\Middleware\NormalizeTenantInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\Auth\Sso\SsoBrowserBinding;
use Enterprise\MultiTenant\Http\Middleware\EnsureModuleAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Modules\Platform\Http\Middleware\EnforceIdempotency;
use Modules\Platform\Http\Middleware\HandleConditionalRequests;
use Modules\Platform\Identity\Http\Middleware\AppendMaskedFieldsMeta;
use Modules\Platform\Identity\Http\Middleware\EnsureCompanyAccess;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

        // HTTPS only, in every environment. EnforceHttps does the job of Laravel's TrustProxies (so X-Forwarded-Proto counts only
        // from the trusted proxies) and is the very first middleware: an insecure request is redirected or refused before
        // anything reads its input.
        $middleware->remove(TrustProxies::class);
        $middleware->api(replace: [EnsureFrontendRequestsAreStateful::class => EnsureHttpsFrontendRequestsAreStateful::class]);

        $middleware->prepend(NormalizeTenantInput::class);
        $middleware->prepend(EnforceHttps::class);

        // Hardening headers (nosniff, no framing, no referrer, CSP, HSTS over HTTPS) on every response.
        $middleware->append(SecurityHeaders::class);

        // The cookie that binds a single sign-on to the browser holds a random secret that means nothing without its stored
        // hash: it is not encrypted (see SsoBrowserBinding).
        $middleware->encryptCookies(except: [SsoBrowserBinding::COOKIE]);

        $middleware->statefulApi();
        $middleware->throttleApi();

        // The `web` group starts a database session per request: cap it per address (limiter `web-public`).
        $middleware->web(append: ['throttle:web-public']);

        // The tenant context and, after it, the two-factor requirement of that tenant.
        $middleware->group('tenant', [
            IdentifyTenant::class,
            EnforceSessionPolicy::class,
            EnsureTwoFactorEnrolled::class,
            EnsureCompanyAccess::class,
            AppendMaskedFieldsMeta::class,
            EnforceIdempotency::class,
            HandleConditionalRequests::class,
        ]);

        $middleware->alias([
            'module.access' => EnsureModuleAccess::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'locale' => SetLocale::class,
            'deprecated' => MarkDeprecated::class,
            'normalize.tenant' => NormalizeTenantInput::class,
            'privacy.sensitive.log' => LogSensitiveDataReadAccess::class,
            'mobile.device' => EnsureMobileDeviceActive::class,
            'feature' => EnsureFeatureFlagEnabled::class,
            'audit.access' => AuditSensitiveAccess::class,
            'audit.permission' => EnsureAuditPermission::class,
            'portal.session' => EnsurePortalSession::class,
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
        // A refused authorization answers with the stable machine-readable code `permission_denied` that every client switches on.
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            $origin = $e instanceof AccessDeniedHttpException ? $e->getPrevious() : $e;

            if (! $request->is('api/*') || $origin instanceof SodDocumentConflictException) {
                return null;
            }

            return response()->json([
                'type' => AuthProblem::PermissionDenied->value,
                'title' => 'Forbidden',
                'status' => 403,
                'code' => AuthProblem::PermissionDenied->code(),
                'detail' => $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
            ], 403, ['Content-Type' => 'application/problem+json']);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
