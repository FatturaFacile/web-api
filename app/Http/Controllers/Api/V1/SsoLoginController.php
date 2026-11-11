<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\Sso\SsoException;
use App\Services\Auth\Sso\SsoLogin;
use App\Services\Auth\SsoSignInCodes;
use App\Services\Auth\TokenMode;
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
        private readonly TokenMode $tokenModes,
        private readonly SsoSignInCodes $signInCodes,
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
        description: 'Completes a single sign-on login: exchanges the authorization code, validates the id_token (signature against the provider JWKS, issuer, audience, expiry, nonce) and signs the user in like the password login does (cookie session, no token; a user with two factors gets a challenge). Without a cookie session on the request (a cross-site call) the answer is a single-use sign_in_code for POST /auth/sso/exchange. A Bearer token only with token_mode=true while the server enables the token mode (otherwise 422). The access is bound to the tenant of the provider. The state is spent on first use: a replay is refused. Any failure is a 401 with a generic message and an audit entry.',
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
    public function callback(Request $request): Response
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', 'max:256'],
            TokenMode::PARAMETER => ['sometimes', 'boolean'],
        ]);

        $tokenMode = $this->tokenModes->resolve($request);

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

        return $this->responder->respondToSso($request, $user, $tenant, $tokenMode);
    }

    #[OA\Post(
        path: '/api/v1/auth/sso/exchange',
        operationId: 'exchangeSsoSignInCode',
        description: 'Exchanges the single-use sign-in code that a cross-site single sign-on handed to the SPA (the SAML assertion posted by the identity provider finishes without a session and redirects the browser to the SPA with `#code=...`, or answers `sign_in_code` to a JSON client) for the cookie session. A stateful https request with the CSRF token is required (GET /sanctum/csrf-cookie first), so only the SPA can complete the sign-in. The code is valid 60 seconds by default and is spent by the first exchange: a replay or an expired code is a 401. Answers like the password login (cookie set, or a two-factor challenge).',
        summary: 'Exchange an SSO sign-in code for the cookie session',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code'], properties: [new OA\Property(property: 'code', type: 'string', minLength: 64, maxLength: 64)])),
        responses: [
            new OA\Response(response: 200, description: 'Authenticated (cookie set, same body as the password login), or a two-factor challenge', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 400, description: 'The request does not come from a stateful https origin (no cookie session)', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 401, description: 'The code is unknown, expired or already used', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 419, description: 'CSRF token mismatch'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function exchange(Request $request): Response
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:64']]);

        if (($blocked = $this->responder->cannotOpenAccess($request, false)) !== null) {
            return $blocked;
        }

        $payload = $this->signInCodes->redeem($validated['code']);
        $user = $payload === null ? null : User::query()->find($payload['user_id']);
        $tenant = $user?->tenants()->whereKey($payload['tenant_id'] ?? '')->first();

        // The account may have changed in the seconds since the identity provider vouched for it.
        if ($user === null || $tenant === null || ! $user->is_active) {
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
