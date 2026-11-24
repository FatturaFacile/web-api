<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\RequestPortalLinkRequest;
use App\Http\Requests\Portal\VerifyPortalLinkRequest;
use App\Http\Responses\ApiResponse;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalAuthService;
use App\Services\Portal\PortalBrandingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PortalAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PortalAuthService $auth,
        private readonly PortalBrandingResolver $branding,
    ) {}

    #[OA\Post(
        path: '/api/v1/portal/auth/request-link',
        operationId: 'requestPortalMagicLink',
        description: 'Sends a single-use sign-in link (15 minutes) to a customer e-mail. The answer is the same whether or not the address is known. The portal is selected by custom domain or the `slug` query parameter.',
        summary: 'Request a portal magic link',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'query', required: false, description: 'Portal slug of the tenant', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email')])),
        responses: [
            new OA\Response(response: 202, description: 'Request accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function requestLink(RequestPortalLinkRequest $request): JsonResponse
    {
        $setting = $this->branding->resolve($request);

        if ($setting !== null) {
            $this->auth->requestLink($setting, (string) $request->validated('email'));
        }

        return $this->successResponse(message: __('portal.messages.link_requested'), statusCode: Response::HTTP_ACCEPTED);
    }

    #[OA\Post(
        path: '/api/v1/portal/auth/verify',
        operationId: 'verifyPortalMagicLink',
        description: 'Exchanges the magic link for a bearer session with the `portal:customer` scope. When the tenant requires a second factor the first call answers `otp_required` and mails a code; the call is repeated with the `otp`.',
        summary: 'Verify a portal magic link',
        security: [],
        tags: ['Customer Portal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token'], properties: [new OA\Property(property: 'token', type: 'string'), new OA\Property(property: 'otp', type: 'string', nullable: true), new OA\Property(property: 'accept_privacy', type: 'boolean', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Signed in, or a code is required', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Unknown link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired or was already used', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong code', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 429, description: 'Too many wrong codes', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(VerifyPortalLinkRequest $request): JsonResponse
    {
        $result = $this->auth->verify(
            (string) $request->validated('token'),
            $request->validated('otp'),
            $request->boolean('accept_privacy'),
        );

        if ($result['status'] === 'otp_required') {
            return $this->successResponse(data: ['status' => 'otp_required'], message: __('portal.messages.otp_required'));
        }

        $contact = $result['contact'];

        return $this->successResponse(
            data: [
                'status' => 'authenticated',
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'abilities' => ['portal:customer'],
                'expires_at' => $result['expires_at'],
                'customer' => [
                    'id' => $contact->id,
                    'name' => $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')),
                    'email' => $contact->email,
                ],
            ],
            message: __('portal.messages.authenticated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/portal/auth/logout',
        operationId: 'logoutPortal',
        description: 'Revokes the portal session of the bearer token.',
        summary: 'Sign out of the portal',
        security: [['portalBearer' => []]],
        tags: ['Customer Portal'],
        responses: [
            new OA\Response(response: 200, description: 'Signed out', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var PortalAccessToken $session */
        $session = $request->attributes->get('portal_token');
        $this->auth->logout($session);

        return $this->successResponse(message: __('portal.messages.logged_out'));
    }
}
