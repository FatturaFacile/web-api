<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Http\Controllers;

use App\Exceptions\SodConflictException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Identity\Application\EffectivePermissionResolver;
use Modules\Platform\Identity\Application\PermissionRegistry;
use Modules\Platform\Identity\Application\RoleService;
use Modules\Platform\Identity\Domain\Exceptions\PrivilegeEscalationException;
use Modules\Platform\Identity\Http\Requests\AssignRoleRequest;
use Modules\Platform\Identity\Http\Requests\StoreRoleRequest;
use Modules\Platform\Identity\Http\Requests\UpsertAbacRuleRequest;
use Modules\Platform\Identity\Http\Requests\UpsertFieldPolicyRequest;
use Modules\Platform\Identity\Http\Resources\RoleResource;
use Modules\Platform\Identity\Infrastructure\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roles of the tenant (and the read-only system roles), their ABAC rules and field policies, and role assignments.
 * Authorization is done by the `can:settings.roles.*` / `can:settings.users.update` route middleware.
 */
final class IdentityRoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RoleService $roles,
        private readonly PermissionRegistry $registry,
        private readonly EffectivePermissionResolver $resolver,
    ) {}

    public function catalog(): JsonResponse
    {
        return $this->successResponse(['catalog' => $this->registry->catalog()]);
    }

    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();

        return $this->successResponse(RoleResource::collection($roles)->resolve());
    }

    public function show(string $role): JsonResponse
    {
        $model = Role::query()->with(['permissions', 'abacRules', 'fieldPolicies'])->findOrFail($role);

        return $this->successResponse((new RoleResource($model))->resolve())->setEtag(md5((string) $model->updated_at?->toIso8601String()));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $role = $this->roles->create($this->tenantId(), (string) $request->validated('name'), $request->validated('description'), (array) $request->validated('permissions'));

            return $this->successResponse((new RoleResource($role->load('permissions')))->resolve(), 'Role created', Response::HTTP_CREATED);
        });
    }

    public function update(StoreRoleRequest $request, string $role): JsonResponse
    {
        $model = Role::query()->findOrFail($role);

        $ifMatch = trim((string) $request->header('If-Match'), '"');
        if ($ifMatch !== '' && $ifMatch !== md5((string) $model->updated_at?->toIso8601String())) {
            return $this->problemDetailsResponse('Precondition Failed', 'The role changed since you loaded it.', Response::HTTP_PRECONDITION_FAILED);
        }

        return $this->guard(function () use ($request, $model): JsonResponse {
            $updated = $this->roles->update($model, $request->validated('name'), $request->validated('description'), $request->has('permissions') ? (array) $request->validated('permissions') : null);

            return $this->successResponse((new RoleResource($updated->load('permissions')))->resolve());
        });
    }

    public function destroy(string $role): JsonResponse
    {
        $model = Role::query()->findOrFail($role);

        return $this->guard(function () use ($model): JsonResponse {
            $this->roles->delete($model);

            return $this->successResponse(null, 'Role deleted');
        });
    }

    public function clone(Request $request, string $role): JsonResponse
    {
        $source = Role::query()->findOrFail($role);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:120']])['name'];

        return $this->guard(function () use ($source, $name): JsonResponse {
            $copy = $this->roles->clone($source, $this->tenantId(), $name);

            return $this->successResponse((new RoleResource($copy->load(['permissions', 'abacRules', 'fieldPolicies'])))->resolve(), 'Role cloned', Response::HTTP_CREATED);
        });
    }

    public function abacRules(string $role): JsonResponse
    {
        return $this->successResponse((new RoleResource(Role::query()->with('abacRules')->findOrFail($role)))->resolve()['abac_rules'] ?? []);
    }

    public function updateAbacRules(UpsertAbacRuleRequest $request, string $role): JsonResponse
    {
        $model = Role::query()->findOrFail($role);

        return $this->guard(function () use ($request, $model): JsonResponse {
            $this->roles->upsertAbacRules($model, (array) $request->validated('rules'));

            return $this->successResponse(null, 'ABAC rules saved');
        });
    }

    public function fieldPolicies(string $role): JsonResponse
    {
        return $this->successResponse((new RoleResource(Role::query()->with('fieldPolicies')->findOrFail($role)))->resolve()['field_policies'] ?? []);
    }

    public function updateFieldPolicies(UpsertFieldPolicyRequest $request, string $role): JsonResponse
    {
        $model = Role::query()->findOrFail($role);

        return $this->guard(function () use ($request, $model): JsonResponse {
            $this->roles->upsertFieldPolicies($model, (array) $request->validated('policies'));

            return $this->successResponse(null, 'Field policies saved');
        });
    }

    public function assign(AssignRoleRequest $request, string $user): JsonResponse
    {
        $tenantId = $this->tenantId();
        $target = User::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenantId))->findOrFail($user);

        return $this->guard(function () use ($request, $target, $tenantId): JsonResponse {
            /** @var User $actor */
            $actor = $request->user();
            $this->roles->assign($actor, $target, $tenantId, (array) $request->validated('role_ids'), $request->validated('company_id'));

            return $this->successResponse(null, 'Roles assigned');
        });
    }

    public function profilePermissions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $request->header('X-Company-Id') ?: null;
        $snapshot = $this->resolver->snapshot($user, $this->tenantId(), $company);

        return $this->successResponse([
            'permissions' => $snapshot['permissions'],
            'field_policies' => $snapshot['field_policies'],
            'company_id' => $company,
        ]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    /**
     * @param  callable(): JsonResponse  $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (PrivilegeEscalationException $e) {
            return $this->problemDetailsResponse('Privilege Escalation', $e->getMessage(), $e->httpStatus);
        } catch (SodConflictException $e) {
            return $this->problemDetailsResponse('Segregation of Duties', $e->getMessage(), Response::HTTP_CONFLICT);
        } catch (DomainException $e) {
            return $this->problemDetailsResponse('Conflict', $e->getMessage(), Response::HTTP_CONFLICT);
        }
    }
}
