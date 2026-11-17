<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\TokenMode;
use App\Services\Mobile\DeviceSessionService;
use App\Services\Mobile\PkceAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth2 authorization-code + PKCE (S256) for the native mobile app.
 *
 * The credentials go through the regular login pipeline (lockout, e-mail verification, tenant approval, SSO-only
 * tenants, password expiry, concurrent session limit) in token mode, so the mobile app is never easier to get into than
 * the web app. The Bearer token that login issues is held (encrypted) behind a single-use code and handed over only to
 * the client that proves the PKCE verifier. Users with two factors get the usual challenge from the login: they finish it
 * with `POST /auth/two-factor-challenge` (token mode) and then register the device with `POST /mobile/devices`.
 */
class MobileAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PkceAuthorizationService $pkce,
        private readonly DeviceSessionService $devices,
    ) {}

    /** Step 1: log in (token mode) and exchange the access for a one-time authorization code. */
    #[OA\Post(
        path: '/api/v1/mobile/auth/pkce/authorize',
        operationId: 'mobileAuthPkceAuthorize',
        description: 'Step 1 of the authorization-code + PKCE (S256) flow: runs the regular login in token mode (send the usual login credentials next to the PKCE fields) and returns a single-use authorization code. For users with two-factor authentication the login challenge is returned instead of a code.',
        summary: 'PKCE authorize',
        security: [],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['email', 'password', 'code_challenge', 'code_challenge_method'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email'), new OA\Property(property: 'password', type: 'string'), new OA\Property(property: 'code_challenge', type: 'string', minLength: 43, maxLength: 128), new OA\Property(property: 'code_challenge_method', type: 'string', enum: ['S256'])])),
        responses: [
            new OA\Response(response: 200, description: 'Authorization code issued (or the login challenge for two-factor users)', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'expires_in', type: 'integer')]), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function authorizeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code_challenge' => ['required', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['required', 'in:S256'],
        ]);

        $request->merge([TokenMode::PARAMETER => true]);

        /** @var JsonResponse $login */
        $login = app(AuthController::class)->login($request);

        if ($login->getStatusCode() !== Response::HTTP_OK) {
            return $login; // problem+json of the login: wrong credentials, locked, unverified, ...
        }

        $body = $login->getData(true)['data'] ?? [];

        if (empty($body['token'])) {
            return $login; // two-factor challenge: finish it on /auth/two-factor-challenge
        }

        $code = $this->pkce->issueCode(
            (string) $body['user']['id'],
            (string) ($body['active_tenant']['id'] ?? ''),
            $data['code_challenge'],
            ['token' => Crypt::encryptString((string) $body['token'])],
        );

        return $this->successResponse(
            data: ['code' => $code, 'expires_in' => (int) config('mobile.pkce.code_ttl_seconds')],
            message: 'Authorization code issued'
        );
    }

    /** Step 2: redeem the code with the PKCE verifier and register the device. */
    #[OA\Post(
        path: '/api/v1/mobile/auth/pkce/token',
        operationId: 'mobileAuthPkceToken',
        description: 'Step 2: redeems the one-time authorization code with the PKCE verifier, registers the device and returns the Bearer access token.',
        summary: 'PKCE token exchange',
        security: [],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['code', 'code_verifier', 'device_id', 'platform'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'code_verifier', type: 'string', minLength: 43, maxLength: 128), new OA\Property(property: 'device_id', type: 'string', maxLength: 191), new OA\Property(property: 'device_name', type: 'string', maxLength: 191, nullable: true), new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android', 'android_rugged_zebra']), new OA\Property(property: 'app_version', type: 'string', maxLength: 30, nullable: true), new OA\Property(property: 'biometric_enrolled', type: 'boolean', nullable: true), new OA\Property(property: 'push_token', type: 'string', nullable: true), new OA\Property(property: 'push_provider', type: 'string', enum: ['fcm', 'apns'], nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Access token issued', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'access_token', type: 'string'), new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'), new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'), new OA\Property(property: 'device_session_id', type: 'string', format: 'uuid')]), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 400, description: 'Invalid grant', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'device_id' => ['required', 'string', 'max:191'],
            'device_name' => ['nullable', 'string', 'max:191'],
            'platform' => ['required', 'in:'.implode(',', config('mobile.platforms'))],
            'app_version' => ['nullable', 'string', 'max:30'],
            'biometric_enrolled' => ['nullable', 'boolean'],
            'push_token' => ['nullable', 'string'],
            'push_provider' => ['nullable', 'in:fcm,apns'],
        ]);

        $grant = $this->pkce->redeem($data['code'], $data['code_verifier']);
        $plain = $grant === null ? null : Crypt::decryptString($grant['context']['token']);
        $record = $plain === null ? null : PersonalAccessToken::findToken($plain);
        $user = $record?->tokenable;

        if (! $user instanceof User || ! $user->is_active) {
            return $this->problemDetailsResponse('Invalid Grant', 'The authorization code is invalid, expired or the verifier does not match.', Response::HTTP_BAD_REQUEST);
        }

        $device = $this->devices->register($user, $grant['tenant_id'], $data, (int) $record->getKey());

        return $this->successResponse(
            data: [
                'access_token' => $plain,
                'token_type' => 'Bearer',
                'expires_at' => $record->expires_at?->toIso8601String(),
                'tenant_id' => $grant['tenant_id'],
                'device_session_id' => $device->id,
            ],
            message: 'Authenticated successfully',
            statusCode: Response::HTTP_CREATED
        );
    }
}
