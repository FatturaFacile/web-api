<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\Sso\SsoException;
use App\Services\Auth\Sso\SsoLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single sign-on with OpenID Connect (authorization code flow with PKCE) for the tenants that configured a provider.
 * Two steps: `start` returns the URL of the identity provider, the client sends the browser there; the provider sends
 * the browser back to the redirect URI of the tenant, and the client posts the `code` and `state` to `callback`, which
 * answers like the password login (cookie or Bearer token, or a two-factor challenge). SAML is not supported.
 *
 * Failures answer with one generic message; the reason (forged token, wrong audience, expired token, replayed state,
 * unknown user, domain not allowed...) goes to the audit ledger.
 */
class SsoLoginController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SsoLogin $login,
        private readonly LoginResponder $responder,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/sso/start',
        operationId: 'startSsoLogin',
        description: 'Starts a single sign-on login for a tenant and returns the authorization URL of its identity provider (OpenID Connect, authorization code with PKCE). The state is valid once, for a few minutes. A tenant without a ready provider is a 404.',
        summary: 'Start an SSO login',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SsoStartRequest')),
        responses: [
            new OA\Response(response: 200, description: 'The authorization URL', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'authorization_url', type: 'string', format: 'uri')], type: 'object')])),
            new OA\Response(response: 404, description: 'No ready SSO provider for this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid']]);

        try {
            $url = $this->login->start($validated['tenant_id']);
        } catch (SsoException) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_unavailable_title'),
                detail: __('enterprise_auth.errors.sso_unavailable_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return $this->successResponse(data: ['authorization_url' => $url]);
    }

    #[OA\Post(
        path: '/api/v1/auth/sso/callback',
        operationId: 'completeSsoLogin',
        description: 'Completes a single sign-on login: exchanges the authorization code, validates the id_token (signature against the provider JWKS, issuer, audience, expiry, nonce) and signs the user in like the password login does (cookie session or Bearer token; a user with two factors gets a challenge). The access is bound to the tenant of the provider. The state is spent on first use: a replay is refused. Any failure is a 401 with a generic message and an audit entry.',
        summary: 'Complete an SSO login',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SsoCallbackRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated (same body as the password login), or a two-factor challenge', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'The sign-in could not be completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', 'max:256'],
        ]);

        try {
            [$user, $tenant] = $this->login->complete($validated['code'], $validated['state']);
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
}
