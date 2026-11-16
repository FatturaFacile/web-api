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
