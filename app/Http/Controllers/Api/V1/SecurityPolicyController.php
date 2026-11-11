<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateSecurityPolicyRequest;
use App\Http\Resources\SecurityPolicyResource;
use App\Http\Responses\ApiResponse;
use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\TenantSecurityPolicies;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-tenant security policy: password rules, lockout, session limits, second factor and SSO-only mode. The rules
 * are enforced by the login, password and session code (see TenantSecurityPolicies). A tenant can only tighten the
 * platform defaults. Reserved to owners and administrators (module `system`).
 */
class SecurityPolicyController extends Controller
{
    use ApiResponse;

    /**
     * Request field to column, for the fields that map one to one.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'password.min_length' => 'password_min_length',
        'password.require_mixed_case' => 'password_require_mixed_case',
        'password.require_numbers' => 'password_require_numbers',
        'password.require_symbols' => 'password_require_symbols',
        'password.history_count' => 'password_history_count',
        'password.max_age_days' => 'password_max_age_days',
        'lockout.max_attempts' => 'lockout_max_attempts',
        'lockout.minutes' => 'lockout_minutes',
        'session.idle_timeout_minutes' => 'session_idle_timeout_minutes',
        'session.max_concurrent_sessions' => 'max_concurrent_sessions',
        'two_factor.roles' => 'two_factor_roles',
        'sso_only' => 'sso_only',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSecurityPolicies $policies,
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/security/policy',
        operationId: 'getSecurityPolicy',
        description: 'The security policy enforced for the active tenant: password rules (length, complexity, history, maximum age), lockout, session idle timeout and concurrent sessions, second factor requirement (none, everyone, or by role) and SSO-only mode.',
        summary: 'Get the tenant security policy',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The policy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SecurityPolicy')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(): JsonResponse
    {
        return $this->successResponse(data: SecurityPolicyResource::make($this->policies->describe($this->activeTenant())));
    }

    #[OA\Put(
        path: '/api/v1/settings/security/policy',
        operationId: 'updateSecurityPolicy',
        description: 'Updates the groups that are sent. Enforced on the server: a password that breaks the rules is refused at change and reset; an account is locked after the configured wrong passwords; an access idle for too long is revoked; the oldest accesses beyond the concurrent limit are revoked at the next login; members without two factors (everyone, or the listed roles) reach only the setup routes; with sso_only a password access is refused. An administrator cannot require two factors without having them, nor turn on sso_only without a ready provider and an own SSO identity (409).',
        summary: 'Update the tenant security policy',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SecurityPolicy')),
        responses: [
            new OA\Response(response: 200, description: 'The policy now in force', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SecurityPolicy')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The change would lock the administrator out', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateSecurityPolicyRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenant = $this->activeTenant();
        $tenantId = (string) $tenant->id;
        $input = $request->validated();

        $attributes = [];

        foreach (self::COLUMNS as $field => $column) {
            if (Arr::has($input, $field)) {
                $attributes[$column] = Arr::get($input, $field);
            }
        }

        $mode = Arr::get($input, 'two_factor.require_for');

        if ($mode === 'none') {
            $attributes['two_factor_roles'] = [];
        }

        $roles = (array) ($attributes['two_factor_roles'] ?? $this->policies->forTenant($tenant)->twoFactorRoles);
        $finalMode = (string) ($mode ?? $this->policies->twoFactorMode($tenant));
        $ownRole = $admin->getRoleInTenant($tenantId);

        $requiresOwnTwoFactor = $finalMode === 'all' || ($finalMode === 'roles' && in_array($ownRole, $roles, true));

        if ($requiresOwnTwoFactor && ! $this->twoFactor->isEnabled($admin)) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.admin_setup_required_title'),
                detail: __('two_factor.errors.admin_setup_required_detail'),
                status: Response::HTTP_CONFLICT,
                type: AuthProblem::TwoFactorSetupRequired->value,
            );
        }

        if (($attributes['sso_only'] ?? false) === true && ! $this->ssoReadyFor($admin, $tenantId)) {
            return $this->problemDetailsResponse(
                title: __('enterprise_auth.errors.sso_not_ready_title'),
                detail: __('enterprise_auth.errors.sso_not_ready_detail'),
                status: Response::HTTP_CONFLICT,
                type: AuthProblem::SsoRequired->value,
            );
        }

        $this->policies->save($tenantId, $attributes);

        if ($mode !== null) {
            $this->twoFactor->setRequiredBy($tenantId, $mode === 'all');
        }

        $this->audit->record(RegistrationAudit::SECURITY_POLICY_CHANGED, (string) $admin->id, ['changes' => $attributes + ($mode === null ? [] : ['two_factor_require_for' => $mode])], $tenantId);

        return $this->successResponse(
            data: SecurityPolicyResource::make($this->policies->describe(Tenant::query()->findOrFail($tenantId))),
            message: __('enterprise_auth.messages.policy_updated'),
        );
    }

    /**
     * SSO-only needs a ready provider and an administrator who can still sign in: platform administrators are exempt from
     * the mode, every other administrator must already be linked to the provider.
     */
    private function ssoReadyFor(User $admin, string $tenantId): bool
    {
        $provider = SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($provider === null || ! $provider->isReady()) {
            return false;
        }

        return $admin->is_platform_admin
            || SsoIdentity::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $admin->id)->exists();
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, 'Missing tenant context.');

        return $tenant;
    }
}
