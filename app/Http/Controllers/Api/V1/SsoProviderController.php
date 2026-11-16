<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpsertSsoProviderRequest;
use App\Http\Resources\SsoProviderResource;
use App\Http\Responses\ApiResponse;
use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\Sso\OidcDiscovery;
use App\Services\Auth\Sso\SsoException;
use App\Services\Auth\Sso\SsoUrlGuard;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OpenID Connect identity provider of the active tenant (one per tenant): issuer and endpoints (read from the
 * discovery document or typed), client id and secret (stored encrypted, never returned), allowed email domains, role
 * mapping and just-in-time provisioning. SAML is not supported. Reserved to owners and administrators (module `system`).
 */
class SsoProviderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OidcDiscovery $discovery,
        private readonly SsoUrlGuard $guard,
        private readonly TenantSecurityPolicies $policies,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/security/sso',
        operationId: 'getSsoProvider',
        description: 'The OpenID Connect provider configured for the active tenant, or null. The client secret is never returned.',
        summary: 'Get the SSO provider',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The provider, or data null when none is configured', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SsoProvider', nullable: true)])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        $provider = $this->provider((string) $this->activeTenant()->id);

        return $this->successResponse(data: $provider === null ? null : SsoProviderResource::make($provider));
    }

    #[OA\Put(
        path: '/api/v1/settings/security/sso',
        operationId: 'upsertSsoProvider',
        description: 'Creates or replaces the OpenID Connect provider of the tenant. With `use_discovery` (default) the endpoints are read from `<issuer>/.well-known/openid-configuration`, whose `issuer` must match; otherwise the three endpoints are required. Endpoints must be https and must not point to private or loopback addresses. `client_secret` is stored encrypted; omit it to keep the current one. Roles from the mapping or the default role can never be `owner`.',
        summary: 'Configure the SSO provider',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SsoProviderInput')),
        responses: [
            new OA\Response(response: 200, description: 'The provider', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SsoProvider')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'SSO-only mode is on and the change would disable the provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error, unreachable or inconsistent discovery document, unsafe endpoint', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function upsert(UpsertSsoProviderRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;
        $input = $request->validated();
        $existing = $this->provider($tenantId);

        $attributes = [
            'name' => $input['name'],
            'issuer' => $input['issuer'],
            'redirect_uri' => $input['redirect_uri'],
            'client_id' => $input['client_id'],
            'scopes' => $input['scopes'] ?? 'openid email profile',
            'allowed_email_domains' => array_values(array_unique(array_map(fn (string $domain): string => strtolower(trim($domain)), $input['allowed_email_domains']))),
            'role_claim' => $input['role_claim'] ?? null,
            'role_mapping' => $input['role_mapping'] ?? null,
            'default_role' => $input['default_role'] ?? null,
            'sync_role_on_login' => (bool) ($input['sync_role_on_login'] ?? false),
            'jit_provisioning' => (bool) ($input['jit_provisioning'] ?? false),
            'enabled' => (bool) ($input['enabled'] ?? false),
        ];

        if (array_key_exists('client_secret', $input)) {
            $attributes['client_secret'] = blank($input['client_secret']) ? null : $input['client_secret'];
        }

        $attributes += $this->endpoints($input);
        $supported = Arr::pull($attributes, 'token_endpoint_auth_methods_supported');
        $attributes['token_endpoint_auth_method'] = $this->authMethod($input['token_endpoint_auth_method'] ?? null, $supported, $existing?->token_endpoint_auth_method);

        if (array_key_exists('end_session_endpoint', $input)) {
            $attributes['end_session_endpoint'] = $this->safeUrl($input['end_session_endpoint'], 'end_session_endpoint');
        }

        if (array_key_exists('post_logout_redirect_uri', $input)) {
            $attributes['post_logout_redirect_uri'] = $input['post_logout_redirect_uri'];
        }

        if ($attributes['enabled'] === false && $this->policies->forTenant($tenantId)->ssoOnly) {
            return $this->ssoOnlyConflict();
        }

        $provider = $existing ?? new SsoProvider;
        $provider->forceFill(['tenant_id' => $tenantId])->fill($attributes)->save();

        $this->audit->record(RegistrationAudit::SSO_PROVIDER_SAVED, (string) $admin->id, [
            'provider' => $provider->id,
            'issuer' => $provider->issuer,
            'enabled' => $provider->enabled,
            'jit_provisioning' => $provider->jit_provisioning,
            'created' => $existing === null,
        ], $tenantId);

        return $this->successResponse(data: SsoProviderResource::make($provider->refresh()), message: __('enterprise_auth.messages.sso_saved'));
    }

    #[OA\Post(
        path: '/api/v1/settings/security/sso/discovery',
        operationId: 'refreshSsoDiscovery',
        description: 'Reads again the discovery document of the configured issuer and updates the endpoints (for example after the provider rotated its keys location).',
        summary: 'Refresh the SSO endpoints from discovery',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The provider with the refreshed endpoints', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SsoProvider')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No provider configured', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Discovery failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function refreshDiscovery(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;
        $provider = $this->provider($tenantId);

        if ($provider === null) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_not_configured_title'),
                detail: __('enterprise_auth.errors.sso_not_configured_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $discovered = $this->discover($provider->issuer);
        Arr::pull($discovered, 'token_endpoint_auth_methods_supported');
        $provider->forceFill($discovered)->save();
        $this->audit->record(RegistrationAudit::SSO_PROVIDER_SAVED, (string) $admin->id, ['provider' => $provider->id, 'discovery_refreshed' => true], $tenantId);

        return $this->successResponse(data: SsoProviderResource::make($provider->refresh()), message: __('enterprise_auth.messages.sso_saved'));
    }

    #[OA\Delete(
        path: '/api/v1/settings/security/sso',
        operationId: 'deleteSsoProvider',
        description: 'Removes the provider and the identity links of its users. Refused while SSO-only mode is on. Users provisioned through the provider keep their account and can set a password with the reset flow.',
        summary: 'Remove the SSO provider',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No provider configured', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 409, description: 'SSO-only mode is on', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;
        $provider = $this->provider($tenantId);

        if ($provider === null) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_not_configured_title'),
                detail: __('enterprise_auth.errors.sso_not_configured_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        if ($this->policies->forTenant($tenantId)->ssoOnly) {
            return $this->ssoOnlyConflict();
        }

        $provider->delete();
        $this->audit->record(RegistrationAudit::SSO_PROVIDER_REMOVED, (string) $admin->id, ['provider' => $provider->id], $tenantId);

        return $this->successResponse(message: __('enterprise_auth.messages.sso_removed'));
    }

    /**
     * The endpoints to store: read from the discovery document, or the typed ones after the safety check.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function endpoints(array $input): array
    {
        if (($input['use_discovery'] ?? true) === true) {
            return $this->discover((string) $input['issuer']);
        }

        try {
            $endpoints = [];

            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                $this->guard->assertSafe((string) $input[$field], $field);
                $endpoints[$field] = $input[$field];
            }
        } catch (SsoException $exception) {
            throw ValidationException::withMessages(['issuer' => __('enterprise_auth.errors.endpoint_unsafe', ['reason' => $exception->reason])]);
        }

        return $endpoints + ['discovered_at' => null];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function discover(string $issuer): array
    {
        try {
            return $this->discovery->fetch($issuer) + ['discovered_at' => now()];
        } catch (SsoException $exception) {
            throw ValidationException::withMessages(['issuer' => __('enterprise_auth.errors.discovery_failed', ['reason' => $exception->reason])]);
        }
    }

    /**
     * The client authentication method of the token endpoint: the one asked for (it must be among those the provider
     * advertises), else, with a discovery document, client_secret_basic when advertised, else client_secret_post, else
     * what the provider already had, else client_secret_post.
     *
     * @param  list<string>|null  $supported
     *
     * @throws ValidationException
     */
    private function authMethod(?string $requested, ?array $supported, ?string $current): string
    {
        if ($requested !== null) {
            if ($supported !== null && ! in_array($requested, $supported, true)) {
                throw ValidationException::withMessages(['token_endpoint_auth_method' => __('enterprise_auth.errors.auth_method_unsupported')]);
            }

            return $requested;
        }

        if ($supported !== null) {
            foreach (['client_secret_basic', 'client_secret_post'] as $method) {
                if (in_array($method, $supported, true)) {
                    return $method;
                }
            }
        }

        return $current ?? 'client_secret_post';
    }

    /**
     * @throws ValidationException
     */
    private function safeUrl(mixed $url, string $field): ?string
    {
        if (blank($url)) {
            return null;
        }

        try {
            $this->guard->assertSafe((string) $url, $field);
        } catch (SsoException $exception) {
            throw ValidationException::withMessages([$field => __('enterprise_auth.errors.endpoint_unsafe', ['reason' => $exception->reason])]);
        }

        return (string) $url;
    }

    private function ssoOnlyConflict(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('enterprise_auth.errors.sso_only_active_title'),
            detail: __('enterprise_auth.errors.sso_only_active_detail'),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function provider(string $tenantId): ?SsoProvider
    {
        return SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, __('tenant_errors.missing_context'));

        return $tenant;
    }
}
