<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\SodCheckRequest;
use Modules\Governance\Http\Requests\SodExceptionRequest;
use Modules\Governance\Http\Requests\SodRuleRequest;
use Modules\Governance\Http\Resources\SodExceptionResource;
use Modules\Governance\Http\Resources\SodRuleResource;
use Modules\Governance\Http\Resources\SodViolationResource;
use Modules\Governance\Models\GovSodException;
use Modules\Governance\Models\GovSodRule;
use Modules\Governance\Models\GovSodViolation;
use Modules\Governance\Services\SodService;
use OpenApi\Attributes as OA;

/**
 * Segregation of duties: the rules, the register of violations found on the memberships of the company and the
 * exceptions approved for a limited time. Owners, administrators, accountants and members granted governance.manage
 * keep the rules and the register; exceptions are granted and revoked by owners, administrators and members granted
 * governance.approve, never for themselves.
 */
final class SegregationOfDutiesController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly SodService $sod) {}

    #[OA\Get(path: '/api/v1/governance/sod/rules', operationId: 'listGovSodRules', description: 'Segregation of duties rules by code. Filters: severity, is_active.', summary: 'List segregation of duties rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['warn', 'block'])),
        new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    ], responses: [
        new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovSodRule'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function rules(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', GovSodRule::class);

        return SodRuleResource::collection(
            GovSodRule::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('severity'), static fn ($query) => $query->where('severity', (string) $request->query('severity')))
                ->when($request->filled('is_active'), static fn ($query) => $query->where('is_active', $request->boolean('is_active')))
                ->orderBy('code')
                ->get()
        );
    }

    #[OA\Post(path: '/api/v1/governance/sod/rules', operationId: 'storeGovSodRule', description: 'Adds a rule: nobody may hold a duty of group A together with a duty of group B.', summary: 'Add a segregation of duties rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovSodRuleRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Rule added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovSodRule')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function storeRule(SodRuleRequest $request): JsonResponse
    {
        $this->authorize('create', GovSodRule::class);
        $data = $request->validated();
        $tenantId = $this->tenantId();

        if (GovSodRule::withTrashed()->where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'The code has already been taken.', 'errors' => ['code' => ['The code has already been taken.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new SodRuleResource($this->sod->createRule($tenantId, $data)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/governance/sod/rules/{id}', operationId: 'updateGovSodRule', description: 'Changes the title, duties, severity or activation of a rule. The code cannot change.', summary: 'Update a segregation of duties rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovSodRuleRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovSodRule')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function updateRule(SodRuleRequest $request, string $id): SodRuleResource
    {
        $rule = $this->findRule($id);
        $this->authorize('update', $rule);

        return new SodRuleResource($this->sod->updateRule($rule, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/governance/sod/rules/{id}', operationId: 'deleteGovSodRule', description: 'Deletes a rule. Its violations stay in the register until the next scan resolves them.', summary: 'Delete a segregation of duties rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 204, description: 'Rule deleted'),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function destroyRule(string $id): Response
    {
        $rule = $this->findRule($id);
        $this->authorize('delete', $rule);
        $rule->delete();

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/governance/sod/rules/seed-defaults', operationId: 'seedGovSodRules', description: 'Adopts the standard rules the company does not have yet (payments, payroll, ledger). Running it again creates nothing twice.', summary: 'Adopt the standard rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], responses: [
        new OA\Response(response: 200, description: 'Rules adopted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'created', type: 'integer'), new OA\Property(property: 'skipped', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function seedDefaults(): JsonResponse
    {
        $this->authorize('create', GovSodRule::class);

        return response()->json(['data' => $this->sod->seedDefaults($this->tenantId())]);
    }

    #[OA\Post(path: '/api/v1/governance/sod/check', operationId: 'checkGovSodAssignment', description: 'Tests a role with permissions against the active rules without saving anything. Each conflict says whether an active exception already covers the member, when a user_id is given in the query.', summary: 'Check an assignment for conflicts', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovSodCheckRequest')), tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Conflicts found', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
            new OA\Property(property: 'blocked', type: 'boolean'),
            new OA\Property(property: 'conflicts', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'rule_code', type: 'string'),
                new OA\Property(property: 'rule_title', type: 'string'),
                new OA\Property(property: 'severity', type: 'string'),
                new OA\Property(property: 'duty_a', type: 'string'),
                new OA\Property(property: 'duty_b', type: 'string'),
                new OA\Property(property: 'excepted', type: 'boolean'),
            ], type: 'object')),
        ], type: 'object')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function check(SodCheckRequest $request): JsonResponse
    {
        $this->authorize('create', GovSodRule::class);
        $validated = $request->validated();
        $tenantId = $this->tenantId();
        $userId = $request->query('user_id');

        $conflicts = array_map(fn (array $conflict): array => [
            'rule_id' => $conflict['rule']->id,
            'rule_code' => $conflict['rule']->code,
            'rule_title' => $conflict['rule']->title,
            'severity' => $conflict['rule']->severity,
            'duty_a' => $conflict['duty_a'],
            'duty_b' => $conflict['duty_b'],
            'excepted' => is_string($userId) && $this->sod->activeException($tenantId, $userId, (string) $conflict['rule']->id) !== null,
        ], $this->sod->conflictsOf($tenantId, $validated['role'], array_values($validated['permissions'] ?? [])));

        return response()->json(['data' => [
            'blocked' => collect($conflicts)->contains(static fn (array $conflict): bool => $conflict['severity'] === 'block' && ! $conflict['excepted']),
            'conflicts' => $conflicts,
        ]]);
    }

    #[OA\Get(path: '/api/v1/governance/sod/violations', operationId: 'listGovSodViolations', description: 'The register of violations, newest first. Filters: status, rule_id, user_id.', summary: 'List segregation of duties violations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'excepted', 'resolved'])),
        new OA\Parameter(name: 'rule_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Violations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovSodViolation'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function violations(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', GovSodRule::class);

        return SodViolationResource::collection(
            GovSodViolation::query()
                ->with('rule')
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('rule_id'), static fn ($query) => $query->where('rule_id', (string) $request->query('rule_id')))
                ->when($request->filled('user_id'), static fn ($query) => $query->where('user_id', (string) $request->query('user_id')))
                ->orderByDesc('detected_at')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/sod/scan', operationId: 'scanGovSodViolations', description: 'Compares every active membership of the company with the active rules and updates the register: new conflicts open a violation, conflicts covered by an active exception are marked excepted, conflicts that are gone are resolved. Owners and administrators are not checked.', summary: 'Scan the memberships for conflicts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], responses: [
        new OA\Response(response: 200, description: 'Scan result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'members', type: 'integer'), new OA\Property(property: 'open', type: 'integer'), new OA\Property(property: 'excepted', type: 'integer'), new OA\Property(property: 'resolved', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function scan(): JsonResponse
    {
        $this->authorize('create', GovSodRule::class);

        return response()->json(['data' => $this->sod->scan($this->tenantId())]);
    }

    #[OA\Get(path: '/api/v1/governance/sod/exceptions', operationId: 'listGovSodExceptions', description: 'Exceptions, newest first. Filters: user_id, rule_id, active=1 (not revoked and not expired).', summary: 'List segregation of duties exceptions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'rule_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Exceptions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovSodException'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function exceptions(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', GovSodRule::class);

        return SodExceptionResource::collection(
            GovSodException::query()
                ->with('rule')
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('user_id'), static fn ($query) => $query->where('user_id', (string) $request->query('user_id')))
                ->when($request->filled('rule_id'), static fn ($query) => $query->where('rule_id', (string) $request->query('rule_id')))
                ->when($request->boolean('active'), static fn ($query) => $query->where('status', 'active')->whereNull('revoked_at')->whereDate('expires_at', '>=', now()->toDateString()))
                ->orderByDesc('created_at')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/sod/exceptions', operationId: 'storeGovSodException', description: 'Lets one member hold the conflicting duties of a rule until a date within one year. The caller becomes the approver and cannot be the member the exception is for. Needs the authority of an owner, an administrator or a member granted governance.approve.', summary: 'Grant an exception', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovSodExceptionRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Exception granted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovSodException')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function storeException(SodExceptionRequest $request): JsonResponse
    {
        $this->authorize('approve', new GovSodException(['tenant_id' => $this->tenantId()]));

        $exception = $this->sod->grantException($this->tenantId(), (string) $this->userId(), $request->validated());

        return (new SodExceptionResource($exception->load('rule')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/governance/sod/exceptions/{id}/revoke', operationId: 'revokeGovSodException', description: 'Ends an exception before its expiry. The violation opens again at the next scan.', summary: 'Revoke an exception', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Exception revoked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovSodException')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function revokeException(string $id): SodExceptionResource
    {
        $exception = GovSodException::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('approve', $exception);

        return new SodExceptionResource($this->sod->revokeException($exception)->load('rule'));
    }

    private function findRule(string $id): GovSodRule
    {
        return GovSodRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
