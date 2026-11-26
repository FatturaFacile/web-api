<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CheckPasswordPolicyRequest;
use App\Http\Requests\Auth\PasswordRulesRequest;
use App\Http\Resources\Auth\PasswordPolicyCheckResource;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Security\EffectivePolicy;
use App\Services\Auth\Security\PasswordPolicy;
use App\Services\Auth\Security\TenantSecurityPolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Lets the clients show the password rules and check a password while the user types it (registration, reset, change).
 * The rules are the platform defaults tightened by the policy of the tenant (or of the strictest tenant of the user).
 * The public check never reveals the password history: the `reused` rule exists only for the signed-in user.
 */
class PasswordPolicyController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantSecurityPolicies $policies,
        private readonly PasswordPolicy $passwordPolicy,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/password-policy/rules',
        operationId: 'getPublicPasswordRules',
        description: 'The password rules in force: the platform defaults, or the policy of the tenant given as tenant_id. Public, for the registration and reset screens.',
        summary: 'Password rules (public)',
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'tenant_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PasswordPolicyRules')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function rules(PasswordRulesRequest $request): JsonResponse
    {
        return $this->successResponse(data: PasswordPolicyCheckResource::make($this->describe($this->resolve($request->validated('tenant_id'), null), false)));
    }

    #[OA\Get(
        path: '/api/v1/auth/password-policy',
        operationId: 'getOwnPasswordPolicy',
        description: 'The password rules that apply to the signed-in user: the strictest policy among their tenants, with the history length.',
        summary: 'Password rules of the signed-in user',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'The rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PasswordPolicyRules')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(data: PasswordPolicyCheckResource::make($this->describe($this->policies->forUser($user), true)));
    }

    #[OA\Post(
        path: '/api/v1/auth/password-policy/check',
        operationId: 'checkPasswordPolicy',
        description: 'Checks a password against the policy (platform defaults, or the policy of tenant_id / of the tenants of email) and returns the checklist with an indicative strength from 0 to 4. With the compromised-password check on, only the first five characters of the SHA-1 of the password are sent to the Have I Been Pwned range API. Public and throttled; nothing is stored.',
        summary: 'Check a password against the policy',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['password'], properties: [
            new OA\Property(property: 'password', type: 'string', nullable: true),
            new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The checklist', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PasswordPolicyCheck')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function check(CheckPasswordPolicyRequest $request): JsonResponse
    {
        $password = (string) $request->validated('password');
        $policy = $this->resolve($request->validated('tenant_id'), $request->validated('email'));

        $rules = $this->passwordPolicy->checklist($policy, $password);
        $breached = collect($rules)->contains(static fn (array $rule): bool => $rule['code'] === 'breached' && ! $rule['passed']);

        return $this->successResponse(data: PasswordPolicyCheckResource::make([
            'rules' => array_map(static fn (array $rule): array => ['code' => $rule['code'], 'passed' => $rule['passed'], 'message' => $rule['message']], $rules),
            'acceptable' => $password !== '' && collect($rules)->every(static fn (array $rule): bool => $rule['passed']),
            'strength' => $this->passwordPolicy->strength($policy, $password, $breached),
        ]));
    }

    /**
     * The policy of the tenant, of the tenants of the user with this email (strictest wins), or the platform defaults.
     */
    private function resolve(?string $tenantId, ?string $email): EffectivePolicy
    {
        $policy = $this->policies->forTenant($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists() ? $tenantId : null);

        if ($email !== null) {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

            if ($user !== null) {
                $policy = $policy->strictest($this->policies->forUser($user));
            }
        }

        return $policy;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(EffectivePolicy $policy, bool $withHistory): array
    {
        $rules = ['min_length'];

        foreach ([[$policy->passwordRequireMixedCase, 'mixed_case'], [$policy->passwordRequireNumbers, 'numbers'], [$policy->passwordRequireSymbols, 'symbols']] as [$on, $code]) {
            if ($on) {
                $rules[] = $code;
            }
        }

        if ($withHistory && $policy->passwordHistoryCount > 0) {
            $rules[] = 'reused';
        }

        if ($policy->passwordCheckBreached) {
            $rules[] = 'breached';
        }

        return [
            'min_length' => $policy->passwordMinLength,
            'require_mixed_case' => $policy->passwordRequireMixedCase,
            'require_numbers' => $policy->passwordRequireNumbers,
            'require_symbols' => $policy->passwordRequireSymbols,
            'check_breached' => $policy->passwordCheckBreached,
            'history_count' => $withHistory ? $policy->passwordHistoryCount : null,
            'max_age_days' => $withHistory ? $policy->passwordMaxAgeDays : null,
            'rules' => $rules,
        ];
    }
}
