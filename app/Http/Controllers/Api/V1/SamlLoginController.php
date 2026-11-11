<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\Saml\SamlLogin;
use App\Services\Auth\Sso\SsoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAML 2.0 single sign-on, SP-initiated, for the tenants that configured a SAML provider (see SamlLogin). `start`
 * returns the URL of the identity provider; the identity provider posts the response to the assertion consumer service
 * of the tenant, which answers like the password login (Bearer token, or a two-factor challenge). Failures answer with
 * one generic message; the reason (forged or unsigned assertion, wrong audience, expired, replayed, wrong request,
 * unknown user...) goes to the audit ledger.
 */
class SamlLoginController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SamlLogin $saml,
        private readonly LoginResponder $responder,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/saml/start',
        operationId: 'startSamlLogin',
        description: 'Starts a SAML login for a tenant and returns the URL of its identity provider (AuthnRequest, HTTP-Redirect binding, with a RelayState valid once for a few minutes). A tenant without a ready SAML provider is a 404.',
        summary: 'Start a SAML login',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SsoStartRequest')),
        responses: [
            new OA\Response(response: 200, description: 'The redirect URL', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'redirect_url', type: 'string', format: 'uri')], type: 'object')])),
            new OA\Response(response: 404, description: 'No ready SAML provider for this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid']]);

        try {
            $url = $this->saml->start($validated['tenant_id']);
        } catch (SsoException) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_unavailable_title'),
                detail: __('enterprise_auth.errors.sso_unavailable_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return $this->successResponse(data: ['redirect_url' => $url]);
    }

    #[OA\Post(
        path: '/api/v1/auth/saml/{tenantId}/acs',
        operationId: 'samlAssertionConsumerService',
        description: 'Assertion consumer service of the tenant (HTTP-POST binding): receives `SAMLResponse` and `RelayState` from the identity provider. Strict mode: the assertion must be signed by a configured certificate, addressed to this tenant (Audience, Destination), within its validity (NotOnOrAfter), an answer to the request this RelayState started (InResponseTo), and not seen before. IdP-initiated responses are refused. Answers like the password login, in token mode (a page of the SPA can relay the posted values and the cookie mode applies). The access is bound to the tenant.',
        summary: 'SAML assertion consumer service',
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: [
            new OA\MediaType(mediaType: 'application/x-www-form-urlencoded', schema: new OA\Schema(required: ['SAMLResponse', 'RelayState'], properties: [
                new OA\Property(property: 'SAMLResponse', type: 'string', description: 'Base64 SAML response.'),
                new OA\Property(property: 'RelayState', type: 'string'),
            ])),
            new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(required: ['SAMLResponse', 'RelayState'], properties: [
                new OA\Property(property: 'SAMLResponse', type: 'string'),
                new OA\Property(property: 'RelayState', type: 'string'),
            ])),
        ]),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated (same body as the password login), or a two-factor challenge', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'The sign-in could not be completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function acs(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'SAMLResponse' => ['required', 'string', 'max:2097152'],
            'RelayState' => ['required', 'string', 'max:256'],
        ]);

        try {
            [$user, $tenant] = $this->saml->complete($tenantId, $validated['SAMLResponse'], $validated['RelayState'], $request);
        } catch (SsoException) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_failed_title'),
                detail: __('enterprise_auth.errors.sso_failed_detail'),
                status: Response::HTTP_UNAUTHORIZED,
                type: AuthProblem::SsoLoginFailed->value,
            );
        }

        return $this->responder->respondToSso($request, $user, $tenant);
    }

    #[OA\Get(
        path: '/api/v1/auth/saml/{tenantId}/metadata',
        operationId: 'samlServiceProviderMetadata',
        description: 'The SAML service provider metadata of the tenant (XML): entity id, assertion consumer service, single logout service and, when configured, the signing certificate. This URL is also the entity id (Audience) of the tenant. A tenant without a SAML provider is a 404.',
        summary: 'SAML service provider metadata',
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Metadata XML', content: new OA\MediaType(mediaType: 'application/samlmetadata+xml', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 404, description: 'No SAML provider for this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function metadata(string $tenantId): Response
    {
        try {
            $xml = $this->saml->metadata($tenantId);
        } catch (SsoException) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_unavailable_title'),
                detail: __('enterprise_auth.errors.sso_unavailable_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return response($xml, Response::HTTP_OK, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    #[OA\Get(
        path: '/api/v1/auth/saml/{tenantId}/slo',
        operationId: 'samlSingleLogoutService',
        description: 'Single logout service of the tenant (HTTP-Redirect binding). A LogoutRequest of the identity provider must be signed; it ends the accesses of that user opened through this tenant, and the browser is redirected to the identity provider with the LogoutResponse. A LogoutResponse is acknowledged with 204.',
        summary: 'SAML single logout service',
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Logout response acknowledged'),
            new OA\Response(response: 302, description: 'Redirect to the identity provider with the LogoutResponse'),
            new OA\Response(response: 400, description: 'Missing, unsigned or invalid logout message', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function slo(Request $request, string $tenantId): Response
    {
        try {
            $url = $this->saml->logout($tenantId, $request);
        } catch (SsoException) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.logout_invalid_title'),
                detail: __('enterprise_auth.errors.logout_invalid_detail'),
                status: Response::HTTP_BAD_REQUEST,
            );
        }

        return $url === null ? response()->noContent() : redirect()->away($url);
    }
}
