<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Sms\SmsDeliveryException;
use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmedPasswordRequest;
use App\Http\Requests\Auth\ConfirmEmailTwoFactorRequest;
use App\Http\Requests\Auth\EnableSmsTwoFactorRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\OtpThrottledException;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\SecondFactorCodes;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Auth\UserSessions;
use App\Services\Sms\PhoneNumber;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Domain\Events\MfaDisabled;
use Modules\Platform\Identity\Domain\Events\MfaEnabled;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SMS one-time code as a second factor of the signed-in user: enrolment (password and phone number, then the code
 * received), removal, and a code for a sensitive change of an account.
 */
class TwoFactorSmsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly SecondFactorCodes $codes,
        private readonly RegistrationAudit $audit,
        private readonly TenantContext $tenantContext,
        private readonly UserSessions $sessions,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/two-factor/sms/enable',
        operationId: 'twoFactorSmsEnable',
        description: 'Starts the enrolment of the SMS factor: after a password confirmation a six-digit code is sent by SMS to the given number (E.164, for example +41791234567; valid 10 minutes, five tries, one code a minute and five an hour). The factor is active only after the confirm call, and the number is stored only then (encrypted).',
        summary: 'Start the SMS code enrolment',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'phone'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'phone', type: 'string', example: '+41791234567', description: 'E.164 number; spaces, dots, dashes and brackets are ignored'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Code sent: phone (masked), expires_in and resend_available_in in seconds', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'SMS factor already active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong password or phone number not in E.164 format', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Code asked for too often', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 503, description: 'The SMS gateway could not deliver the message', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function enable(EnableSmsTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->twoFactor->hasSms($user)) {
            return $this->alreadyEnabledResponse();
        }

        $phone = (string) $request->validated('phone');

        return $this->sendCode($user, SecondFactorCodes::PURPOSE_ENROL, RegistrationAudit::TWO_FACTOR_SETUP_STARTED, ['mfa_method' => TwoFactorAuthentication::METHOD_SMS], $phone);
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/sms/confirm',
        operationId: 'twoFactorSmsConfirm',
        description: 'Activates the SMS factor with the code that was received. The response carries the recovery codes when the user had none yet (shown only this once); the other accesses of the user end when this is the first factor.',
        summary: 'Confirm the SMS code enrolment',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'],
            properties: [new OA\Property(property: 'code', type: 'string', example: '482913')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'SMS factor active: recovery_codes (possibly empty) and methods', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'SMS factor already active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong, expired or already used code, or no enrolment in progress', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function confirm(ConfirmEmailTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->twoFactor->hasSms($user)) {
            return $this->alreadyEnabledResponse();
        }

        $firstFactor = ! $this->twoFactor->isEnabled($user);
        $recoveryCodes = $this->twoFactor->confirmSms($user, (string) $request->validated('code'));

        if ($recoveryCodes === null) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.code_invalid_title'),
                detail: __('two_factor.errors.code_invalid_detail'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                type: AuthProblem::TwoFactorCodeInvalid->value,
            );
        }

        $this->audit->record(RegistrationAudit::TWO_FACTOR_SMS_ENABLED, (string) $user->id, ['mfa_method' => TwoFactorAuthentication::METHOD_SMS]);
        $this->announce($user, MfaEnabled::class);

        // Accesses opened with the password alone end: from now on every login needs the second factor.
        if ($firstFactor) {
            $this->revokeOtherAccesses($request, $user);
        }

        return $this->successResponse(
            data: ['recovery_codes' => $recoveryCodes, 'methods' => $this->twoFactor->availableMethods($user)],
            message: __('two_factor.messages.sms_enabled')
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/sms/send',
        operationId: 'twoFactorSmsSend',
        description: 'Sends a verification code by SMS to the signed-in user who has the SMS factor, for a sensitive change (password change, turning the authenticator app off) that asks for a second factor. The code is sent as sms_code to that call. Same limits as the other codes.',
        summary: 'Send an SMS code for a sensitive change',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Code sent: expires_in and resend_available_in in seconds', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'SMS factor not active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 429, description: 'Code asked for too often', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 503, description: 'The SMS gateway could not deliver the message', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->hasSms($user)) {
            return $this->notEnabledResponse();
        }

        return $this->sendCode($user, SecondFactorCodes::PURPOSE_VERIFY, RegistrationAudit::TWO_FACTOR_SMS_CODE_SENT, ['auth_method' => 'session']);
    }

    #[OA\Delete(
        path: '/api/v1/auth/two-factor/sms',
        operationId: 'twoFactorSmsDisable',
        description: 'Turns the SMS factor off after a password confirmation and forgets the number. Without another factor the recovery codes and the trusted devices go too, and the other accesses end. Owners and administrators of a tenant that enforces two-factor authentication cannot remove their last factor.',
        summary: 'Disable the SMS code factor',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TwoFactorPasswordRequest')),
        responses: [
            new OA\Response(response: 200, description: 'SMS factor removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Last factor of a role that must keep two-factor authentication', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'SMS factor not active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong password', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function destroy(ConfirmedPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->hasSms($user)) {
            return $this->notEnabledResponse();
        }

        $lastFactor = ! $this->twoFactor->otherFactorsExist($user, TwoFactorAuthentication::METHOD_SMS);

        if ($lastFactor && $this->twoFactor->mustKeepTwoFactor($user)) {
            $response = $this->problemDetailsResponse(
                title: __('two_factor.errors.last_factor_title'),
                detail: __('two_factor.errors.last_factor_detail'),
                status: Response::HTTP_FORBIDDEN,
                type: AuthProblem::MfaCannotBeDisabled->value,
            );
            $response->setData([...(array) $response->getData(true), 'code' => AuthProblem::MfaCannotBeDisabled->code()]);

            return $response;
        }

        $this->twoFactor->disableSms($user);
        $this->audit->record(RegistrationAudit::TWO_FACTOR_SMS_DISABLED, (string) $user->id, ['mfa_method' => TwoFactorAuthentication::METHOD_SMS]);

        if ($lastFactor) {
            $this->announce($user, MfaDisabled::class);
            $this->revokeOtherAccesses($request, $user);
        }

        return $this->successResponse(
            data: ['methods' => $this->twoFactor->availableMethods($user)],
            message: __('two_factor.messages.sms_disabled')
        );
    }

    /**
     * @param  SecondFactorCodes::PURPOSE_*  $purpose
     * @param  array<string, mixed>  $details
     */
    private function sendCode(User $user, string $purpose, string $auditAction, array $details, ?string $phone = null): JsonResponse
    {
        try {
            $expiresIn = $this->codes->send($user, $purpose, SecondFactorCodes::CHANNEL_SMS, $phone);
        } catch (OtpThrottledException $e) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.email_throttled_title'),
                detail: __('two_factor.errors.email_throttled_detail', ['seconds' => $e->retryAfterSeconds]),
                status: Response::HTTP_TOO_MANY_REQUESTS,
                type: AuthProblem::TwoFactorEmailThrottled->value,
            )->header('Retry-After', (string) $e->retryAfterSeconds);
        } catch (SmsDeliveryException) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.sms_unavailable_title'),
                detail: __('two_factor.errors.sms_unavailable_detail'),
                status: Response::HTTP_SERVICE_UNAVAILABLE,
                type: AuthProblem::SmsDeliveryFailed->value,
            );
        }

        $this->audit->record($auditAction, (string) $user->id, $details);

        return $this->successResponse(
            data: [
                'phone' => PhoneNumber::mask((string) ($phone ?? $user->two_factor_sms_phone)),
                'expires_in' => $expiresIn,
                'resend_available_in' => $this->codes->resendAvailableIn($user, SecondFactorCodes::CHANNEL_SMS),
            ],
            message: __('two_factor.messages.sms_code_sent'),
        );
    }

    /**
     * @param  class-string<MfaEnabled|MfaDisabled>  $event
     */
    private function announce(User $user, string $event): void
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $user->tenants()->value('tenants.id');

        if (is_string($tenantId)) {
            event(new $event($tenantId, (string) $user->id, TwoFactorAuthentication::METHOD_SMS, (string) $user->id));
        }
    }

    private function revokeOtherAccesses(Request $request, User $user): void
    {
        $this->sessions->revokeOthers(
            $user,
            UserSessions::currentTokenId($user),
            $request->hasSession() ? $request->session()->getId() : null,
        );
    }

    private function alreadyEnabledResponse(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('two_factor.errors.sms_already_enabled_title'),
            detail: __('two_factor.errors.sms_already_enabled_detail'),
            status: Response::HTTP_CONFLICT,
        );
    }

    private function notEnabledResponse(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('two_factor.errors.sms_not_enabled_title'),
            detail: __('two_factor.errors.sms_not_enabled_detail'),
            status: Response::HTTP_CONFLICT,
        );
    }
}
