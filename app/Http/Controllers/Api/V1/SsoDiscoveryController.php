<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\Sso\SsoDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Public discovery for the login page: which single sign-on to offer for an email address. See SsoDiscovery for what the
 * answer reveals (little) and why.
 */
class SsoDiscoveryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SsoDiscovery $discovery) {}

    #[OA\Post(
        path: '/api/v1/auth/sso/discover',
        operationId: 'discoverSso',
        description: 'Tells the login page whether to offer single sign-on for an email address (or a domain) and which flow to start. Only the domain is looked at. The answer always has the same shape: `data.sso = {available, protocol, tenant_id}`; for an unknown domain and for a domain without SSO it is `{available: false, protocol: null, tenant_id: null}`, produced by the same queries and with the same minimum duration, so the two cannot be told apart. When one tenant has a ready provider for the domain, `available` is true, `protocol` is `oidc` or `saml`, and `tenant_id` is the value to give to POST /auth/sso/start (oidc) or POST /auth/saml/start (saml). A domain claimed by several tenants is ambiguous and is answered as unavailable. Whether the password login is still allowed is not part of the answer. Always HTTP 200 for a well-formed input; a malformed email or domain is a 422 (it says nothing about the tenants). Rate limited per client address.',
        summary: 'Discover the SSO of an email domain',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            description: 'Give `email` or `domain`.',
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'anna.rossi@example.com'),
                new OA\Property(property: 'domain', type: 'string', example: 'example.com'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'The SSO to offer, if any', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'sso', type: 'object', properties: [
                        new OA\Property(property: 'available', type: 'boolean'),
                        new OA\Property(property: 'protocol', type: 'string', enum: ['oidc', 'saml'], nullable: true),
                        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
                    ]),
                ]),
            ])),
            new OA\Response(response: 422, description: 'Malformed email or domain', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function discover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'string', 'max:254', 'required_without:domain'],
            'domain' => ['nullable', 'string', 'max:253', 'required_without:email'],
        ]);

        $domain = strtolower(trim((string) (isset($validated['email']) ? substr((string) strrchr((string) $validated['email'], '@'), 1) : ($validated['domain'] ?? ''))));

        if (preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) !== 1) {
            throw ValidationException::withMessages([isset($validated['email']) ? 'email' : 'domain' => __('validation.email', ['attribute' => 'email'])]);
        }

        return $this->successResponse(data: ['sso' => $this->discovery->find($domain)])->header('Cache-Control', 'no-store');
    }
}
