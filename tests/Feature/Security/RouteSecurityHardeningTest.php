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
        $candidates = [(string) Str::uuid(), '2026', 'sample', 'LETTERING', ...explode('|', (string) $constraint)];

        return $constraint === null ? $candidates[0] : (collect($candidates)->first(fn (string $value): bool => $value !== '' && preg_match("#^(?:{$constraint})$#", $value) === 1) ?? $candidates[0]);
    }, $route->uri());
}

function isPublicApiEndpoint(string $uri, string $method): bool
{
    $publicPatterns = [
        'api/documentation',
        'api/oauth2-callback',
        'api/v1/auth/login',
        'api/v1/auth/two-factor-challenge',
        'api/v1/auth/2fa/challenge',
        'api/v1/auth/passkeys/login',
        'api/v1/auth/register',
        'api/v1/auth/verify-email',
        'api/v1/auth/resend-verification-email',
        'api/v1/auth/forgot-password',
        'api/v1/auth/resend-reset-password-email',
        'api/v1/auth/validate-reset-token',
        'api/v1/auth/reset-password',
        // Single sign-on: the sign-in itself, so there is no session yet. Protected by a single-use state/nonce (OIDC, SAML),
        // by the signature of the assertion/id_token and by the tenant policy, see Auth\SsoOidcLoginTest and Auth\SamlLoginTest.
        'api/v1/auth/sso',
        'api/v1/auth/saml',
        // SCIM 2.0 provisioning: authenticated by the per-tenant SCIM bearer token (AuthenticateScim), see Auth\ScimProvisioningTest.
        'api/v1/scim/v2',
        // Report download links: authenticated by the signature of the URL (signed, expiring), see Analytics\ScheduledExportsAndSnapshotsTest.
        'api/v1/report-downloads',
        'api/v1/system/bootstrap-support',
        'api/v1/translations',
        // MES Core integration (issue #184): a service caller authenticated by an HMAC signature bound to the tenants of its key (VerifyMesSignature), see Manufacturing\MesSignatureTest.
        'api/v1/manufacturing/mes-sync',
        'api/v1/manufacturing/mes-feedback',
        // Proposal portal (Mod 036): the unguessable token (plus PIN session, OTP or HMAC-signed provider callback) is the credential, see Quoting\CpqQuotingTest.
        'api/v1/public/proposals',
        'api/v1/public/signature-webhooks',
        'api/v1/lookups',
        'api/v1/webhooks',
        'api/v1/portal',
        // Machine feed: authenticated by a tenant API key instead of Sanctum, see Compliance\ApiKeyAndReportingTest.
        'api/v1/reporting',
        'api/v1/workflows/remote-approval',
        // Mod 044 single-use 1-click Email/Push approval token endpoint: signed HMAC-SHA256 + hashed single-use token in approval_tokens selects tenant, throttled by IP.
        'api/v1/procurement/purchase-requisitions/one-click-action',
        'api/v1/association-public',
        'api/v1/association-area',
        'api/v1/association-access/verify',
        'api/v1/association-payments/callback',
        // Family portal of the kindergartens: authenticated by the personal token of a guardian, see Verticals\ChildcareTest.
        'api/v1/childcare-family',
        // Reception tablets: authenticated by the per-kiosk hashed secret, which selects the tenant, see Visitors\VisitorManagementTest.
        'api/v1/visitor-kiosk',
        // SDI provider webhook: authenticated by the X-Webhook-Secret header (hash_equals, rejected with 401 when unset), see SdiNotificationRequest::authorize().
        'api/v1/sdi/webhook',
        // Stored export download: authenticated by the signature of the URL (signed, expiring), see the `signed` middleware of the DataExport module.
        'api/v1/export-downloads',
        // Source file of an AI Inbox job for the review viewer: authenticated by the signature of the URL (signed, expiring), see OcrExtraction\OcrPipelineTest.
        'api/v1/ocr-inbox-downloads',
        // Account-less pages: the secret token of the link (stored hashed) or the careers slug is the credential and selects the tenant, throttled by IP.
        'api/v1/public/contract-signing',
        'api/v1/public/quote-signatures',
        'api/v1/public/careers',
        'api/v1/public/rma',
        // Supplier RFQ portal (Mod 045): authenticated by cryptographic rfq.invitation.token middleware, throttled by IP.
        'api/v1/public/rfq-portal',
        // Academy certificate verification: the unguessable certificate code is the credential; throttled, discloses only what the certificate prints.
        'api/v1/academy/certificates/verify',
        // Supplier portal: authenticated by the personal X-Supplier-Token header (AuthenticateSupplierPortal), which selects the tenant.
        'api/v1/supplier-self-service',
        // Telegram bot webhook: the secret in the URL is compared with hash_equals and unknown/unset secrets get 404.
        'api/v1/platform/telegram/webhook',
        // Pre-login flows: invitation tokens, mobile PKCE (runs the regular login), liveness probe, user-scoped channel authorization (auth:sanctum, own channel only).
        'api/v1/auth/invitations',
        'api/v1/mobile/auth/pkce',
        'api/v1/health/ready',
        'api/v1/broadcasting/auth',
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
