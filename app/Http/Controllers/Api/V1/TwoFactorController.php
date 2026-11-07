<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmedPasswordRequest;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\UpdateTwoFactorPolicyRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Auth\UserSessions;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication of the signed-in user, the tenant policy that requires it and its reset by an administrator.
 */
class TwoFactorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly RegistrationAudit $audit,
        private readonly TenantContext $tenantContext,
        private readonly UserSessions $sessions,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/two-factor',
        operationId: 'twoFactorStatus',
        description: 'Whether the signed-in user has two-factor authentication, whether a setup is pending, how many recovery codes are left and whether the active tenant requires two factors.',
        summary: 'Two-factor status',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Status', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(data: $this->statusOf($user));
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/enable',
        operationId: 'twoFactorEnable',
        description: 'Starts the setup: returns a new secret and its otpauth:// URI for the QR code of the authenticator app. Two factors are active only after the confirm call with a first code. Restarting replaces a pending secret; an active setup must be disabled first.',
        summary: 'Start two-factor setup',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TwoFactorPasswordRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Secret and otpauth URI', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Two factors already active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong password', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function enable(ConfirmedPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->twoFactor->isEnabled($user)) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.already_enabled_title'),
                detail: __('two_factor.errors.already_enabled_detail'),
                status: Response::HTTP_CONFLICT,
            );
        }

        $setup = $this->twoFactor->beginSetup($user);
        $this->audit->record(RegistrationAudit::TWO_FACTOR_SETUP_STARTED, (string) $user->id);

        return $this->successResponse(data: $setup, message: __('two_factor.messages.setup_started'));
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/confirm',
        operationId: 'twoFactorConfirm',
        description: 'Activates two factors with a first code of the app and returns the recovery codes. They are shown only in this response: the client must ask the user to store them.',
        summary: 'Confirm two-factor setup',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'],
            properties: [new OA\Property(property: 'code', type: 'string', example: '123456')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Two factors active; recovery codes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'No pending setup', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong code', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_secret === null || $this->twoFactor->isEnabled($user)) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.no_pending_setup_title'),
                detail: __('two_factor.errors.no_pending_setup_detail'),
                status: Response::HTTP_CONFLICT,
            );
        }

        $recoveryCodes = $this->twoFactor->confirmSetup($user, (string) $request->validated('code'));

        if ($recoveryCodes === null) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.code_invalid_title'),
                detail: __('two_factor.errors.code_invalid_detail'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                type: AuthProblem::TwoFactorCodeInvalid->value,
            );
        }

        $this->audit->record(RegistrationAudit::TWO_FACTOR_ENABLED, (string) $user->id);
        // Accesses opened with the password alone end: from now on every login needs the second factor.
        $this->revokeOtherAccesses($request, $user);

        return $this->successResponse(
            data: ['recovery_codes' => $recoveryCodes],
            message: __('two_factor.messages.enabled')
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/recovery-codes',
        operationId: 'twoFactorRegenerateRecoveryCodes',
        description: 'Replaces every recovery code with new ones, returned only in this response.',
        summary: 'Regenerate recovery codes',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TwoFactorPasswordRequest')),
        responses: [
            new OA\Response(response: 200, description: 'New recovery codes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Two factors not active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong password', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function regenerateRecoveryCodes(ConfirmedPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->isEnabled($user)) {
            return $this->notEnabledResponse();
        }

        $recoveryCodes = $this->twoFactor->regenerateRecoveryCodes($user);
        $this->audit->record(RegistrationAudit::TWO_FACTOR_RECOVERY_CODES_REGENERATED, (string) $user->id);

        return $this->successResponse(
            data: ['recovery_codes' => $recoveryCodes],
            message: __('two_factor.messages.recovery_codes_regenerated')
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/two-factor/disable',
        operationId: 'twoFactorDisable',
        description: 'Turns two factors off. Needs the password and a code of the app or a recovery code. When the tenant requires two factors, the user must set them up again before using the other routes.',
        summary: 'Disable two-factor authentication',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'code', type: 'string', example: '123456', nullable: true),
                new OA\Property(property: 'recovery_code', type: 'string', example: 'abcde-fghjk', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Two factors disabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Two factors not active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Wrong password or code', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->isEnabled($user)) {
            return $this->notEnabledResponse();
        }

        if ($this->twoFactor->verifyCode($user, $request->validated('code'), $request->validated('recovery_code')) === null) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.code_invalid_title'),
                detail: __('two_factor.errors.code_invalid_detail'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                type: AuthProblem::TwoFactorCodeInvalid->value,
            );
        }

        $this->twoFactor->disable($user);
        $this->audit->record(RegistrationAudit::TWO_FACTOR_DISABLED, (string) $user->id);
        $this->revokeOtherAccesses($request, $user);

        return $this->successResponse(data: $this->statusOf($user), message: __('two_factor.messages.disabled'));
    }

    #[OA\Get(
        path: '/api/v1/settings/security/two-factor',
        operationId: 'getTwoFactorPolicy',
        description: 'Whether the active tenant requires two-factor authentication, and which members have it.',
        summary: 'Tenant two-factor policy',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Policy and member status', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not an administrator of the tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function policy(): JsonResponse
    {
        $tenant = $this->activeTenant();

        return $this->successResponse(data: $this->policyOf($tenant));
    }

    #[OA\Put(
        path: '/api/v1/settings/security/two-factor',
        operationId: 'updateTwoFactorPolicy',
        description: 'Requires (or stops requiring) two-factor authentication for every member of the active tenant. Members without two factors can still log in, but only the authentication routes answer until they complete the setup.',
        summary: 'Update tenant two-factor policy',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['require_two_factor'],
            properties: [new OA\Property(property: 'require_two_factor', type: 'boolean', example: true)]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Policy updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not an administrator of the tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The administrator has no two factors yet', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updatePolicy(UpdateTwoFactorPolicyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $required = $request->boolean('require_two_factor');

        // An administrator who requires two factors without having them would lock themself out of the settings.
        if ($required && ! $this->twoFactor->isEnabled($user)) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.admin_setup_required_title'),
                detail: __('two_factor.errors.admin_setup_required_detail'),
                status: Response::HTTP_CONFLICT,
                type: AuthProblem::TwoFactorSetupRequired->value,
            );
        }

        $tenantId = (string) $this->activeTenant()->id;
        $tenant = $this->twoFactor->setRequiredBy($tenantId, $required);

        $this->audit->record(RegistrationAudit::TWO_FACTOR_REQUIREMENT_CHANGED, (string) $user->id, ['require_two_factor' => $required], $tenantId);

        return $this->successResponse(data: $this->policyOf($tenant), message: __('two_factor.messages.policy_updated'));
    }

    #[OA\Post(
        path: '/api/v1/settings/users/{userId}/two-factor/reset',
        operationId: 'resetUserTwoFactor',
        description: 'Turns off the two factors of a member who lost the phone and the recovery codes. Only for members of this tenant alone: a user who also belongs to other tenants must be reset by the platform support, so an administrator cannot weaken the account of another organisation.',
        summary: 'Reset the two factors of a member',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Two factors reset', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not an administrator of the tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not a member of the tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Own account, platform administrator, or member of other tenants', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function resetMember(Request $request, string $userId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenant = $this->activeTenant();

        /** @var User|null $member */
        $member = $tenant->users()->whereKey($userId)->first();

        if ($member === null) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.member_not_found_title'),
                detail: __('two_factor.errors.member_not_found_detail'),
                status: Response::HTTP_NOT_FOUND,
            );
        }

        if ($member->is($admin) || $member->is_platform_admin || $member->tenants()->count() > 1) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.reset_not_allowed_title'),
                detail: __('two_factor.errors.reset_not_allowed_detail'),
                status: Response::HTTP_CONFLICT,
            );
        }

        $this->twoFactor->disable($member);
        // The member's open sessions were opened with the lost factor.
        $this->sessions->revokeAll($member);
        $this->audit->record(RegistrationAudit::TWO_FACTOR_RESET_BY_ADMIN, (string) $member->id, ['reset_by' => (string) $admin->id], (string) $tenant->id);

        return $this->successResponse(data: ['user_id' => $member->id, 'two_factor_enabled' => false], message: __('two_factor.messages.member_reset'));
    }

    /**
     * @return array<string, mixed>
     */
    private function statusOf(User $user): array
    {
        $enabled = $this->twoFactor->isEnabled($user);

        return [
            'enabled' => $enabled,
            'pending_setup' => ! $enabled && $user->two_factor_secret !== null,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'remaining_recovery_codes' => $enabled ? $this->twoFactor->remainingRecoveryCodes($user) : 0,
            'required_by_tenant' => $this->twoFactor->isRequiredBy($this->tenantContext->getTenant()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policyOf(Tenant $tenant): array
    {
        $members = $tenant->users()->get(['users.id', 'users.name', 'users.email', 'users.two_factor_confirmed_at', 'users.two_factor_secret']);

        return [
            'require_two_factor' => $this->twoFactor->isRequiredBy($tenant),
            'members' => $members->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot?->role,
                'two_factor_enabled' => $this->twoFactor->isEnabled($member),
            ])->values(),
        ];
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, 'Missing tenant context.');

        return $tenant;
    }

    private function revokeOtherAccesses(Request $request, User $user): void
    {
        $this->sessions->revokeOthers(
            $user,
            UserSessions::currentTokenId($user),
            $request->hasSession() ? $request->session()->getId() : null,
        );
    }

    private function notEnabledResponse(): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('two_factor.errors.not_enabled_title'),
            detail: __('two_factor.errors.not_enabled_detail'),
            status: Response::HTTP_CONFLICT,
        );
    }
}
