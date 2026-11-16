<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpsertSamlProviderRequest;
use App\Http\Resources\SamlProviderResource;
use App\Http\Responses\ApiResponse;
use App\Models\SamlProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Saml\SamlMetadataImporter;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\Sso\SsoException;
use App\Services\Auth\Sso\SsoUrlGuard;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SAML 2.0 identity provider of the active tenant (one per tenant): metadata URL or XML or typed fields, signing
 * certificates, attribute and role mapping, allowed email domains, just-in-time provisioning. The response carries the
 * service provider URLs (entity id, ACS, SLO, metadata) to give to the identity provider. Reserved to owners and
 * administrators (module `system`).
 */
class SamlProviderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SamlMetadataImporter $importer,
        private readonly SsoUrlGuard $guard,
        private readonly TenantSecurityPolicies $policies,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/security/saml',
        operationId: 'getSamlProvider',
        description: 'The SAML 2.0 provider configured for the active tenant, or null, with the service provider URLs (entity id, assertion consumer service, single logout service, metadata). The private key of the service provider is never returned.',
        summary: 'Get the SAML provider',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The provider, or data null', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SamlProvider', nullable: true)])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        $provider = $this->provider((string) $this->activeTenant()->id);

        return $this->successResponse(data: $provider === null ? null : SamlProviderResource::make($provider));
    }

    #[OA\Put(
        path: '/api/v1/settings/security/saml',
        operationId: 'upsertSamlProvider',
        description: 'Creates or replaces the SAML provider of the tenant. The identity provider comes from `idp_metadata_url` (fetched by the server, https only, no private addresses), pasted `idp_metadata_xml`, or typed `idp_entity_id`, `idp_sso_url` and `idp_certificates`. The assertion must be signed by one of the certificates; strict mode is always on. `sp_private_key` (with `sp_certificate`) makes the service provider sign its requests and is stored encrypted; omit both to keep the current ones. SAML has no verified-email claim: the address of a signed assertion is trusted if its domain is in `allowed_email_domains`.',
        summary: 'Configure the SAML provider',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SamlProviderInput')),
        responses: [
            new OA\Response(response: 200, description: 'The provider', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SamlProvider')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'SSO-only mode is on and the change would disable the provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error, unusable metadata or certificate, unsafe URL', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function upsert(UpsertSamlProviderRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;
        $input = $request->validated();
        $existing = $this->provider($tenantId);

        $attributes = [
            'name' => $input['name'],
            'email_attribute' => $input['email_attribute'] ?? null,
            'name_attribute' => $input['name_attribute'] ?? null,
            'role_attribute' => $input['role_attribute'] ?? null,
            'role_mapping' => $input['role_mapping'] ?? null,
            'default_role' => $input['default_role'] ?? null,
            'sync_role_on_login' => (bool) ($input['sync_role_on_login'] ?? false),
            'jit_provisioning' => (bool) ($input['jit_provisioning'] ?? false),
            'allowed_email_domains' => array_values(array_unique(array_map(fn (string $domain): string => strtolower(trim($domain)), $input['allowed_email_domains']))),
            'enabled' => (bool) ($input['enabled'] ?? false),
        ] + $this->identityProvider($input);

        if ($attributes['enabled'] === false && $this->policies->forTenant($tenantId)->ssoOnly) {
            return $this->ssoOnlyConflict();
        }

        if (array_key_exists('sp_private_key', $input) || array_key_exists('sp_certificate', $input)) {
            $attributes += $this->serviceProviderKey($input);
        }

        $provider = $existing ?? new SamlProvider;
        $provider->forceFill(['tenant_id' => $tenantId])->fill($attributes)->save();

        $this->audit->record(RegistrationAudit::SSO_PROVIDER_SAVED, (string) $admin->id, [
            'provider' => $provider->id,
            'protocol' => 'saml',
            'idp_entity_id' => $provider->idp_entity_id,
            'enabled' => $provider->enabled,
            'jit_provisioning' => $provider->jit_provisioning,
            'created' => $existing === null,
        ], $tenantId);

        return $this->successResponse(data: SamlProviderResource::make($provider->refresh()), message: __('enterprise_auth.messages.sso_saved'));
    }

    #[OA\Post(
        path: '/api/v1/settings/security/saml/metadata',
        operationId: 'refreshSamlMetadata',
        description: 'Fetches again the metadata URL of the configured provider and updates the entity id, endpoints and certificates (for example after the provider rotated its signing certificate).',
        summary: 'Refresh the SAML metadata',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The provider with the refreshed metadata', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SamlProvider')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No provider configured', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'No metadata URL, or the metadata cannot be used', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function refreshMetadata(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;
        $provider = $this->provider($tenantId);

        if ($provider === null) {
            return $this->notConfigured();
        }

        if (blank($provider->idp_metadata_url)) {
            throw ValidationException::withMessages(['idp_metadata_url' => __('enterprise_auth.errors.saml_no_metadata_url')]);
        }

        $provider->forceFill($this->import(fn (): array => $this->importer->fromUrl((string) $provider->idp_metadata_url)) + ['metadata_refreshed_at' => now()])->save();
        $this->audit->record(RegistrationAudit::SSO_PROVIDER_SAVED, (string) $admin->id, ['provider' => $provider->id, 'protocol' => 'saml', 'metadata_refreshed' => true], $tenantId);

        return $this->successResponse(data: SamlProviderResource::make($provider->refresh()), message: __('enterprise_auth.messages.sso_saved'));
    }

    #[OA\Delete(
        path: '/api/v1/settings/security/saml',
        operationId: 'deleteSamlProvider',
        description: 'Removes the SAML provider and the identity links of its users. Refused while SSO-only mode is on.',
        summary: 'Remove the SAML provider',
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
            return $this->notConfigured();
        }

        if ($this->policies->forTenant($tenantId)->ssoOnly) {
            return $this->ssoOnlyConflict();
        }

        $provider->delete();
        $this->audit->record(RegistrationAudit::SSO_PROVIDER_REMOVED, (string) $admin->id, ['provider' => $provider->id, 'protocol' => 'saml'], $tenantId);

        return $this->successResponse(message: __('enterprise_auth.messages.sso_removed'));
    }

    /**
     * The identity provider fields to store, from the metadata URL, the pasted XML or the typed fields.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function identityProvider(array $input): array
    {
        if (filled($input['idp_metadata_xml'] ?? null)) {
            return $this->import(fn (): array => $this->importer->fromXml((string) $input['idp_metadata_xml'])) + ['idp_metadata_url' => null, 'metadata_refreshed_at' => now()];
        }

        if (filled($input['idp_metadata_url'] ?? null)) {
            return $this->import(fn (): array => $this->importer->fromUrl((string) $input['idp_metadata_url'])) + ['idp_metadata_url' => $input['idp_metadata_url'], 'metadata_refreshed_at' => now()];
        }

        return $this->import(function () use ($input): array {
            $this->guard->assertSafe((string) $input['idp_sso_url'], 'idp_sso_url');

            if (filled($input['idp_slo_url'] ?? null)) {
                $this->guard->assertSafe((string) $input['idp_slo_url'], 'idp_slo_url');
            }

            return [
                'idp_entity_id' => (string) $input['idp_entity_id'],
                'idp_sso_url' => (string) $input['idp_sso_url'],
                'idp_slo_url' => $input['idp_slo_url'] ?? null,
                'idp_certificates' => $this->importer->normalisedCertificates(array_values($input['idp_certificates'])),
                'idp_metadata_url' => null,
                'metadata_refreshed_at' => null,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function serviceProviderKey(array $input): array
    {
        $key = $input['sp_private_key'] ?? null;
        $certificate = $input['sp_certificate'] ?? null;

        if (blank($key) && blank($certificate)) {
            return ['sp_private_key' => null, 'sp_certificate' => null];
        }

        $certificateBody = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', (string) $certificate);
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split((string) $certificateBody, 64, "\n").'-----END CERTIFICATE-----';
        $privateKey = openssl_pkey_get_private((string) $key);

        if ($privateKey === false || openssl_x509_read($pem) === false || ! openssl_x509_check_private_key($pem, $privateKey)) {
            throw ValidationException::withMessages(['sp_certificate' => __('enterprise_auth.errors.sp_key_pair')]);
        }

        return ['sp_private_key' => (string) $key, 'sp_certificate' => $certificateBody];
    }

    /**
     * @param  callable(): array<string, mixed>  $import
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function import(callable $import): array
    {
        try {
            return $import();
        } catch (SsoException $exception) {
            throw ValidationException::withMessages(['idp_metadata_url' => __('enterprise_auth.errors.saml_metadata_failed', ['reason' => $exception->reason])]);
        }
    }

    private function notConfigured(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('enterprise_auth.errors.sso_not_configured_title'),
            detail: __('enterprise_auth.errors.sso_not_configured_detail'),
            status: Response::HTTP_NOT_FOUND,
        );
    }

    private function ssoOnlyConflict(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('enterprise_auth.errors.sso_only_active_title'),
            detail: __('enterprise_auth.errors.sso_only_active_detail'),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function provider(string $tenantId): ?SamlProvider
    {
        return SamlProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, __('tenant_errors.missing_context'));

        return $tenant;
    }
}
