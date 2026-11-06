<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->tenantA = Tenant::create([
        'name' => 'Security Audit Alpha SRL',
        'legal_name' => 'Security Audit Alpha SRL',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->userA = User::create([
        'name' => 'Alpha Auditor',
        'email' => 'auditor.alpha.'.Str::random(6).'@fatturafacile.eu',
        'password' => bcrypt('StrongPass123!'),
        'locale' => 'it',
    ]);
    $this->userA->tenants()->attach($this->tenantA->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->tenantB = Tenant::create([
        'name' => 'Security Audit Beta SA',
        'legal_name' => 'Security Audit Beta SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $this->userB = User::create([
        'name' => 'Beta Auditor',
        'email' => 'auditor.beta.'.Str::random(6).'@fatturafacile.eu',
        'password' => bcrypt('StrongPass123!'),
        'locale' => 'de',
    ]);
    $this->userB->tenants()->attach($this->tenantB->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
});

/**
 * Fills the route parameters with a UUID, or with an accepted value where the route constrains the parameter.
 */
function resolveRouteUri(RouteDefinition $route): string
{
    return preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', function (array $parameter) use ($route): string {
        $constraint = $route->wheres[$parameter[1]] ?? null;
        $candidates = [(string) Str::uuid(), '2026', ...explode('|', (string) $constraint)];

        return $constraint === null ? $candidates[0] : collect($candidates)->first(fn (string $value): bool => preg_match("#^(?:{$constraint})$#", $value) === 1);
    }, $route->uri());
}

function isPublicApiEndpoint(string $uri, string $method): bool
{
    $publicPatterns = [
        'api/documentation',
        'api/oauth2-callback',
        'api/v1/auth/login',
        'api/v1/auth/register',
        'api/v1/auth/verify-email',
        'api/v1/auth/resend-verification-email',
        'api/v1/system/bootstrap-support',
        'api/v1/translations',
        'api/v1/lookups',
        'api/v1/webhooks',
        'api/v1/portal',
        'api/v1/workflows/remote-approval',
        'docs',
        'sanctum/csrf-cookie',
        'up',
    ];

    foreach ($publicPatterns as $pattern) {
        if ($uri === $pattern || str_starts_with($uri, $pattern.'/')) {
            return true;
        }
    }

    return false;
}

test('all non-public API routes have auth:sanctum and tenant middleware explicitly attached', function (): void {
    $routes = Route::getRoutes()->getRoutes();
    $missingSecurityRoutes = [];

    foreach ($routes as $route) {
        $uri = $route->uri();
        $methods = $route->methods();
        $method = $methods[0] ?? 'GET';

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        if (isPublicApiEndpoint($uri, $method)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        $hasAuth = in_array('auth:sanctum', $middleware, true);
        // Platform administrators work across tenants: their routes are guarded by a role instead.
        $hasTenant = in_array('tenant', $middleware, true)
            || (str_starts_with($uri, 'api/v1/admin/') && in_array('platform.admin', $middleware, true));

        if (! $hasAuth || ! $hasTenant) {
            $missingSecurityRoutes[] = sprintf(
                '[%s] %s -> missing %s',
                implode('|', $methods),
                $uri,
                implode(', ', array_filter([
                    ! $hasAuth ? 'auth:sanctum' : null,
                    ! $hasTenant ? 'tenant' : null,
                ]))
            );
        }
    }

    expect($missingSecurityRoutes)->toBeEmpty(
        "The following routes lack explicit auth:sanctum or tenant middleware:\n".implode("\n", $missingSecurityRoutes)
    );
});

test('every non-public API endpoint rejects unauthenticated requests with 401 Unauthorized', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);

    $routes = Route::getRoutes()->getRoutes();
    $tested = 0;

    foreach ($routes as $route) {
        $uri = $route->uri();
        $methods = array_diff($route->methods(), ['HEAD']);
        $method = $methods[0] ?? 'GET';

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        if (isPublicApiEndpoint($uri, $method)) {
            continue;
        }

        // Replace route parameters with dummy UUIDs
        $resolvedUri = resolveRouteUri($route);

        $response = $this->json($method, $resolvedUri);

        expect($response->status())->toBe(401, "Expected 401 for [{$method}] {$uri}, got {$response->status()}");
        $tested++;
    }

    expect($tested)->toBeGreaterThan(50);
});

test('every non-public API endpoint rejects cross-tenant requests with 403 Forbidden', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);

    Sanctum::actingAs($this->userA);

    $routes = Route::getRoutes()->getRoutes();
    $tested = 0;

    foreach ($routes as $route) {
        $uri = $route->uri();
        $methods = array_diff($route->methods(), ['HEAD']);
        $method = $methods[0] ?? 'GET';

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        if (isPublicApiEndpoint($uri, $method)) {
            continue;
        }

        // Send tenant B context while authenticated as user A
        $resolvedUri = resolveRouteUri($route);

        $response = $this->withHeader('X-Tenant-Id', $this->tenantB->id)
            ->json($method, $resolvedUri);

        expect($response->status())->toBe(403, "Expected 403 for cross-tenant request to [{$method}] {$uri}, got {$response->status()}");
        $tested++;
    }

    expect($tested)->toBeGreaterThan(50);
});
