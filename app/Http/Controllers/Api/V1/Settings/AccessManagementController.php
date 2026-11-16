<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Exceptions\PlanLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Access\TenantAccessDelegation;
use App\Models\Access\TenantRole;
use App\Models\Access\TenantTeam;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\AccessAudit;
use App\Services\Access\CustomRoleService;
use App\Services\Access\DelegationService;
use App\Services\Access\PlanLimits;
use App\Services\Access\TeamService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom roles, the permission matrix, teams, temporary delegations, plan limits and the change history that owners
 * and administrators manage without technical help.
 */
class AccessManagementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CustomRoleService $roles,
        private readonly TeamService $teams,
        private readonly DelegationService $delegations,
        private readonly PlanLimits $limits,
        private readonly TenantMembershipService $memberships,
    ) {}

    #[OA\Get(path: '/api/v1/settings/access/roles', operationId: 'listCustomRoles', summary: 'List custom roles', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Custom roles', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function roles(): JsonResponse
    {
        return $this->successResponse(data: ['roles' => $this->roles->describeAll($this->tenant()), 'base_roles' => $this->roles->baseRoles(), 'modules' => $this->memberships->modules(), 'levels' => $this->memberships->levels()], message: 'Custom roles retrieved');
    }

    #[OA\Get(path: '/api/v1/settings/access/matrix', operationId: 'getPermissionMatrix', summary: 'Permission matrix by module and action', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Matrix', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function matrix(): JsonResponse
    {
        return $this->successResponse(data: $this->roles->matrix($this->tenant()), message: 'Permission matrix retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/access/roles', operationId: 'createCustomRole', summary: 'Create a custom role', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 201, description: 'Role created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 409, description: 'Plan limit reached', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate($this->roleRules(true));

        try {
            $role = $this->roles->create($this->tenant(), $data);
        } catch (PlanLimitExceededException $exception) {
            return $this->planLimit($exception);
        }

        return $this->successResponse(data: $this->roleResource($role), message: 'Custom role created', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/settings/access/roles/{id}', operationId: 'updateCustomRole', summary: 'Update a custom role and apply it to its members', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Role updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $role = $this->findRole($id);
        $data = $request->validate($this->roleRules(false));

        return $this->successResponse(data: $this->roleResource($this->roles->update($this->tenant(), $role, $data)), message: 'Custom role updated');
    }

    #[OA\Delete(path: '/api/v1/settings/access/roles/{id}', operationId: 'deleteCustomRole', summary: 'Delete a custom role', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Role deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 409, description: 'Role in use', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function destroyRole(string $id): JsonResponse
    {
        try {
            $this->roles->delete($this->tenant(), $this->findRole($id));
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: __('access.errors.role_in_use_title'), detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(message: 'Custom role deleted');
    }

    #[OA\Get(path: '/api/v1/settings/access/teams', operationId: 'listAccessTeams', summary: 'List teams', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Teams', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function teams(): JsonResponse
    {
        return $this->successResponse(data: $this->teams->describeAll($this->tenant()), message: 'Teams retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/access/teams', operationId: 'createAccessTeam', summary: 'Create a team', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 201, description: 'Team created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function storeTeam(Request $request): JsonResponse
    {
        $data = $request->validate($this->teamRules(true));
        $team = $this->teams->create($this->tenant(), $data);

        return $this->successResponse(data: $this->teamResource($team), message: 'Team created', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/settings/access/teams/{id}', operationId: 'updateAccessTeam', summary: 'Update a team', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Team updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function updateTeam(Request $request, string $id): JsonResponse
    {
        $team = $this->findTeam($id);
        $data = $request->validate($this->teamRules(false, $team->id));

        return $this->successResponse(data: $this->teamResource($this->teams->update($this->tenant(), $team, $data)), message: 'Team updated');
    }

    #[OA\Put(path: '/api/v1/settings/access/teams/{id}/members', operationId: 'syncAccessTeamMembers', summary: 'Replace the members of a team', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Members saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function syncTeamMembers(Request $request, string $id): JsonResponse
    {
        $team = $this->findTeam($id);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['uuid']]);

        return $this->successResponse(data: $this->teamResource($this->teams->syncMembers($this->tenant(), $team, array_values($data['user_ids']))), message: 'Team members saved');
    }

    #[OA\Delete(path: '/api/v1/settings/access/teams/{id}', operationId: 'deleteAccessTeam', summary: 'Delete a team', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Team deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function destroyTeam(string $id): JsonResponse
    {
        $this->teams->delete($this->tenant(), $this->findTeam($id));

        return $this->successResponse(message: 'Team deleted');
    }

    #[OA\Get(path: '/api/v1/settings/access/delegations', operationId: 'listAccessDelegations', summary: 'List temporary delegations', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Delegations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function delegations(): JsonResponse
    {
        return $this->successResponse(data: $this->delegations->describeAll($this->tenant()), message: 'Delegations retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/access/delegations', operationId: 'createAccessDelegation', summary: 'Delegate a role temporarily', description: 'The delegator lends their role to the delegate between starts_at and ends_at; an owner lends the admin role. Without delegator_id the signed-in user is the delegator.', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 201, description: 'Delegation created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function storeDelegation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delegator_id' => ['sometimes', 'uuid'],
            'delegate_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'modules' => ['sometimes', 'nullable', 'array'],
            'modules.*' => ['string', Rule::in($this->memberships->modules())],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $tenant = $this->tenant();
        $delegator = isset($data['delegator_id']) ? $tenant->users()->where('users.id', $data['delegator_id'])->first() : $request->user();
        $delegate = $tenant->users()->where('users.id', $data['delegate_id'])->first();

        if (! $delegator instanceof User || ! $delegate instanceof User) {
            return $this->problemDetailsResponse(title: __('access.errors.delegation_invalid_title'), detail: 'Delegator and delegate must be members of the tenant.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $delegation = $this->delegations->create(
                $tenant,
                $delegator,
                $delegate,
                Carbon::parse($data['starts_at']),
                Carbon::parse($data['ends_at']),
                isset($data['modules']) && $data['modules'] !== [] ? array_values($data['modules']) : null,
                $data['reason'] ?? null,
            );
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: __('access.errors.delegation_invalid_title'), detail: $exception->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse(data: collect($this->delegations->describeAll($tenant))->firstWhere('id', $delegation->id), message: 'Delegation created', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/settings/access/delegations/{id}', operationId: 'revokeAccessDelegation', summary: 'Revoke a delegation', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Delegation revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function revokeDelegation(string $id): JsonResponse
    {
        $delegation = TenantAccessDelegation::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->findOrFail($id);
        $this->delegations->revoke($this->tenant(), $delegation);

        return $this->successResponse(message: 'Delegation revoked');
    }

    #[OA\Get(path: '/api/v1/settings/access/limits', operationId: 'getAccessLimits', summary: 'Users and custom roles allowed by the subscription plan', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Limits and usage', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function limits(): JsonResponse
    {
        return $this->successResponse(data: $this->limits->describe($this->tenant()), message: 'Access limits retrieved');
    }

    #[OA\Get(path: '/api/v1/settings/access/audit', operationId: 'listAccessAudit', summary: 'History of changes to users, roles, teams, delegations and invitations', security: [['bearerAuth' => []]], tags: ['Settings', 'Users'], responses: [new OA\Response(response: 200, description: 'Paginated history', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(AccessAudit::types())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()->withoutGlobalScopes()->with('user:id,name,email')
            ->where('tenant_id', $this->tenant()->id)
            ->whereIn('auditable_type', isset($data['type']) ? [$data['type']] : AccessAudit::types())
            ->latest()->latest('id')
            ->paginate((int) ($data['per_page'] ?? 25))
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'type' => $log->auditable_type,
                'subject_id' => $log->auditable_id,
                'actor' => $log->user === null ? null : ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email],
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return $this->successResponse(data: $logs, message: 'Access audit retrieved');
    }

    /**
     * @return array<string, mixed>
     */
    private function roleRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'base_role' => [$required, 'string', Rule::in($this->roles->baseRoles())],
            'modules' => ['sometimes', 'array:'.implode(',', $this->memberships->modules())],
            'modules.*' => ['string', Rule::in($this->memberships->levels())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamRules(bool $creating, ?string $ignoreId = null): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('tenant_teams', 'name')->where('tenant_id', $this->tenant()->id)->whereNull('deleted_at')->ignore($ignoreId)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'custom_role_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_roles', 'id')->where('tenant_id', $this->tenant()->id)->whereNull('deleted_at')],
        ];
    }

    private function tenant(): Tenant
    {
        return $this->tenantContext->getTenant();
    }

    private function findRole(string $id): TenantRole
    {
        return TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->findOrFail($id);
    }

    private function findTeam(string $id): TenantTeam
    {
        return TenantTeam::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function roleResource(TenantRole $role): array
    {
        return collect($this->roles->describeAll($this->tenant()))->firstWhere('id', $role->id) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamResource(TenantTeam $team): array
    {
        return collect($this->teams->describeAll($this->tenant()))->firstWhere('id', $team->id) ?? [];
    }

    private function planLimit(PlanLimitExceededException $exception): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: __('access.errors.plan_limit_title'),
            detail: __('access.errors.plan_limit_detail', ['max' => $exception->max, 'limit' => $exception->limit]),
            status: Response::HTTP_CONFLICT,
        );
    }
}
