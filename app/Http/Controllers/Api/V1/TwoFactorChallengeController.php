<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\EmailOtp;
use App\Services\Auth\EmailOtpThrottledException;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Http\JsonResponse;
use Modules\Platform\Identity\Application\Passkeys\PasskeyException;
use Modules\Platform\Identity\Application\Passkeys\PasskeyService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers of the second-factor challenge of a login: send the email code, and get the options of a security key
 * assertion. Both name the challenge by its token and are answered only for a live challenge.
 */
class TwoFactorChallengeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly EmailOtp $emailOtp,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/two-factor/challenge/email',
        operationId: 'twoFactorChallengeSendEmail',
        description: 'Sends the six-digit code to the email address of the user of the challenge (valid 10 minutes, five tries). Throttled to one code a minute and five an hour per user: a 429 carries Retry-After. The code is then sent as email_code to /auth/two-factor-challenge.',
        summary: 'Send the email code of a login challenge',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_token'],
            properties: [new OA\Property(property: 'challenge_token', type: 'string', example: 'wq3...64 chars')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Code sent: expires_in and resend_available_in in seconds', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Challenge expired or unknown', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The user has no email factor', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Code asked for too often', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function sendEmail(TwoFactorChallengeTokenRequest $request): JsonResponse
    {
        $challenge = $this->twoFactor->findChallenge((string) $request->validated('challenge_token'));
        $user = $challenge === null ? null : User::query()->find($challenge['user_id']);

        if ($challenge === null || $user === null) {
            return $this->invalidChallenge();
        }

        if (! $this->twoFactor->hasEmail($user)) {
            return $this->methodUnavailable();
        }

        try {
            $expiresIn = $this->emailOtp->send($user, EmailOtp::PURPOSE_VERIFY);
        } catch (EmailOtpThrottledException $e) {
            return $this->throttled($e);
        }

        $this->audit->record(RegistrationAudit::TWO_FACTOR_EMAIL_CODE_SENT, (string) $user->id, [
            'auth_method' => (string) ($challenge['auth_method'] ?? 'password'),
        ], $challenge['tenant_id']);

        return $this->successResponse(
            data: ['expires_in' => $expiresIn, 'resend_available_in' => $this->emailOtp->resendAvailableIn($user)],
            message: __('two_factor.messages.email_code_sent'),
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/challenge/webauthn/options',
        operationId: 'twoFactorChallengeWebauthnOptions',
        description: 'Assertion options for a security key or passkey, restricted to the credentials of the user of the challenge. The ceremony is single use. Answer it with webauthn {ceremony_id, credential} at /auth/two-factor-challenge.',
        summary: 'Get the security key options of a login challenge',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_token'],
            properties: [new OA\Property(property: 'challenge_token', type: 'string', example: 'wq3...64 chars')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'ceremony_id and the PublicKeyCredentialRequestOptions', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Challenge expired or unknown', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The user has no security key or passkey', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many attempts', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function webauthnOptions(TwoFactorChallengeTokenRequest $request): JsonResponse
    {
        $challenge = $this->twoFactor->findChallenge((string) $request->validated('challenge_token'));
        $user = $challenge === null ? null : User::query()->find($challenge['user_id']);

        if ($challenge === null || $user === null) {
            return $this->invalidChallenge();
        }

        try {
            $begun = app(PasskeyService::class)->beginSecondFactor($user);
        } catch (PasskeyException) {
            return $this->methodUnavailable();
        }

        return $this->successResponse(data: $begun, message: __('two_factor.messages.webauthn_options'));
    }

    private function invalidChallenge(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('two_factor.errors.challenge_invalid_title'),
            detail: __('two_factor.errors.challenge_invalid_detail'),
            status: Response::HTTP_UNAUTHORIZED,
            type: AuthProblem::TwoFactorChallengeInvalid->value,
        );
    }

    private function methodUnavailable(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('two_factor.errors.method_unavailable_title'),
            detail: __('two_factor.errors.method_unavailable_detail'),
            status: Response::HTTP_CONFLICT,
            type: AuthProblem::TwoFactorMethodUnavailable->value,
        );
    }

    private function throttled(EmailOtpThrottledException $e): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('two_factor.errors.email_throttled_title'),
            detail: __('two_factor.errors.email_throttled_detail', ['seconds' => $e->retryAfterSeconds]),
            status: Response::HTTP_TOO_MANY_REQUESTS,
            type: AuthProblem::TwoFactorEmailThrottled->value,
        )->header('Retry-After', (string) $e->retryAfterSeconds);
    }
}
