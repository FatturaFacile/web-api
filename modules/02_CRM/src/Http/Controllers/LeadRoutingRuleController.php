<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadRoutingServiceInterface;
use Modules\CRM\Http\Requests\ReorderLeadRoutingRulesRequest;
use Modules\CRM\Http\Requests\StoreLeadRoutingRuleRequest;
use Modules\CRM\Http\Resources\LeadRoutingRuleResource;
use Modules\CRM\Models\LeadRoutingRule;
use OpenApi\Attributes as OA;

/**
 * CRUD for the automatic lead assignment rules (geographic, product line, company size, round-robin).
 * Supports both `/api/v1/crm/lead-routing-rules` and `/api/v1/crm/routing-rules` (#194).
 */
final class LeadRoutingRuleController extends Controller
{
    private const array INTAKE_FIELDS = ['name', 'priority', 'strategy', 'assignee_user_ids', 'is_default', 'is_active'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ?LeadRoutingServiceInterface $routing = null,
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/lead-routing-rules',
        operationId: 'listCrmLeadRoutingRules',
        description: 'Automatic lead assignment rules of the active tenant, ordered by priority.',
        summary: 'List lead routing rules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Routing'],
        responses: [
            new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'strategy', type: 'string'), new OA\Property(property: 'priority', type: 'integer'), new OA\Property(property: 'criteria', type: 'object', nullable: true), new OA\Property(property: 'assignee_user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean')]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        if ($this->isRoutingRulesRoute($request)) {
            $this->authorize('viewAny', LeadRoutingRule::class);

            $rules = LeadRoutingRule::query()
                ->where('tenant_id', $this->tenantContext->getTenantId())
                ->orderBy('priority')
                ->orderBy('created_at')
                ->paginate(100);

            return response()->json([
                'data' => LeadRoutingRuleResource::collection($rules->getCollection()),
                'meta' => [
                    'current_page' => $rules->currentPage(),
                    'total' => $rules->total(),
                ],
            ]);
        }

        return response()->json([
            'data' => LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('priority')->get(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/crm/lead-routing-rules',
        operationId: 'createCrmLeadRoutingRule',
        description: 'Creates an assignment rule (geographic, product line, company size or round-robin) that distributes new leads among the listed users.',
        summary: 'Create lead routing rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Routing'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'strategy', 'assignee_user_ids'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150), new OA\Property(property: 'strategy', type: 'string', enum: ['geographic', 'product_line', 'company_size', 'round_robin']), new OA\Property(property: 'priority', type: 'integer', minimum: 0, nullable: true), new OA\Property(property: 'criteria', type: 'object', nullable: true, properties: [new OA\Property(property: 'countries', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'regions', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'product_lines', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'min_size', type: 'integer', minimum: 0), new OA\Property(property: 'max_size', type: 'integer', minimum: 0)]), new OA\Property(property: 'assignee_user_ids', type: 'array', minItems: 1, items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean', nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'strategy', type: 'string'), new OA\Property(property: 'priority', type: 'integer'), new OA\Property(property: 'criteria', type: 'object', nullable: true), new OA\Property(property: 'assignee_user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        if ($this->isRoutingRulesRoute($request)) {
            $this->authorize('create', LeadRoutingRule::class);
            $formRequest = StoreLeadRoutingRuleRequest::createFrom($request);
            $formRequest->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

            $data = $formRequest->safe()->only(self::INTAKE_FIELDS);
            $data['conditions'] = $formRequest->conditions();
            $data['assignee_user_ids'] = array_values((array) ($data['assignee_user_ids'] ?? []));

            $rule = LeadRoutingRule::create(array_merge(
                ['priority' => 100, 'is_default' => false, 'is_active' => true, 'round_robin_cursor' => 0],
                $data,
                ['tenant_id' => $this->tenantContext->getTenantId()],
            ));

            return (new LeadRoutingRuleResource($rule))->response()->setStatusCode(201);
        }

        $rule = LeadRoutingRule::create(['tenant_id' => $this->tenantContext->getTenantId()] + $this->validated($request));

        return response()->json($rule, 201);
    }

    public function show(string $id): JsonResponse
    {
        $rule = LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $this->authorize('view', $rule);

        return (new LeadRoutingRuleResource($rule))->response();
    }

    #[OA\Put(
        path: '/api/v1/crm/lead-routing-rules/{lead_routing_rule}',
        operationId: 'updateCrmLeadRoutingRule',
        description: 'Updates a lead routing rule; only the fields sent are changed.',
        summary: 'Update lead routing rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Routing'],
        parameters: [
            new OA\Parameter(name: 'lead_routing_rule', in: 'path', required: true, description: 'Identifier of the rule', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150), new OA\Property(property: 'strategy', type: 'string', enum: ['geographic', 'product_line', 'company_size', 'round_robin']), new OA\Property(property: 'priority', type: 'integer', minimum: 0, nullable: true), new OA\Property(property: 'criteria', type: 'object', nullable: true, properties: [new OA\Property(property: 'countries', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'regions', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'product_lines', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'min_size', type: 'integer', minimum: 0), new OA\Property(property: 'max_size', type: 'integer', minimum: 0)]), new OA\Property(property: 'assignee_user_ids', type: 'array', minItems: 1, items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Updated rule', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'strategy', type: 'string'), new OA\Property(property: 'priority', type: 'integer'), new OA\Property(property: 'criteria', type: 'object', nullable: true), new OA\Property(property: 'assignee_user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]

    #[OA\Patch(
        path: '/api/v1/crm/lead-routing-rules/{lead_routing_rule}',
        operationId: 'patchCrmLeadRoutingRule',
        description: 'Updates a lead routing rule; only the fields sent are changed.',
        summary: 'Update lead routing rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Routing'],
        parameters: [
            new OA\Parameter(name: 'lead_routing_rule', in: 'path', required: true, description: 'Identifier of the rule', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150), new OA\Property(property: 'strategy', type: 'string', enum: ['geographic', 'product_line', 'company_size', 'round_robin']), new OA\Property(property: 'priority', type: 'integer', minimum: 0, nullable: true), new OA\Property(property: 'criteria', type: 'object', nullable: true, properties: [new OA\Property(property: 'countries', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'regions', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'product_lines', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'min_size', type: 'integer', minimum: 0), new OA\Property(property: 'max_size', type: 'integer', minimum: 0)]), new OA\Property(property: 'assignee_user_ids', type: 'array', minItems: 1, items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Updated rule', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'strategy', type: 'string'), new OA\Property(property: 'priority', type: 'integer'), new OA\Property(property: 'criteria', type: 'object', nullable: true), new OA\Property(property: 'assignee_user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), new OA\Property(property: 'is_active', type: 'boolean')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $rule = LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        if ($this->isRoutingRulesRoute($request)) {
            $this->authorize('update', $rule);
            $formRequest = StoreLeadRoutingRuleRequest::createFrom($request);
            $formRequest->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

            $data = $formRequest->safe()->only(self::INTAKE_FIELDS);
            if ($request->has('conditions')) {
                $data['conditions'] = $formRequest->conditions();
            }
            if (array_key_exists('assignee_user_ids', $data)) {
                $data['assignee_user_ids'] = array_values((array) $data['assignee_user_ids']);
                $data['round_robin_cursor'] = 0;
            }

            $rule->forceFill($data)->save();

            return (new LeadRoutingRuleResource($rule))->response();
        }

        $rule->update($this->validated($request, false));

        return response()->json($rule->fresh());
    }

    #[OA\Delete(
        path: '/api/v1/crm/lead-routing-rules/{lead_routing_rule}',
        operationId: 'deleteCrmLeadRoutingRule',
        description: 'Deletes a lead routing rule.',
        summary: 'Delete lead routing rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Routing'],
        parameters: [
            new OA\Parameter(name: 'lead_routing_rule', in: 'path', required: true, description: 'Identifier of the rule', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Rule deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $rule = LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        if ($this->isRoutingRulesRoute($request)) {
            $this->authorize('delete', $rule);
        }
        $rule->delete();

        return response()->json(null, 204);
    }

    public function reorder(ReorderLeadRoutingRulesRequest $request): JsonResponse
    {
        $this->authorize('create', LeadRoutingRule::class);
        $tenantId = (string) $this->tenantContext->getTenantId();

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->validated('ids')));

        $known = LeadRoutingRule::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->count();
        if ($known !== count($ids)) {
            return response()->json([
                'message' => 'One or more routing rules do not exist.',
                'errors' => ['ids' => ['One or more routing rules do not exist.']],
            ], 422);
        }

        ($this->routing ?? app(LeadRoutingServiceInterface::class))->reorder($tenantId, $ids);

        return $this->index($request);
    }

    private function isRoutingRulesRoute(Request $request): bool
    {
        return str_contains($request->path(), 'crm/routing-rules');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'strategy' => [$required, 'in:geographic,product_line,company_size,round_robin'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'criteria' => ['nullable', 'array'],
            'criteria.countries' => ['nullable', 'array'],
            'criteria.regions' => ['nullable', 'array'],
            'criteria.product_lines' => ['nullable', 'array'],
            'criteria.min_size' => ['nullable', 'integer', 'min:0'],
            'criteria.max_size' => ['nullable', 'integer', 'min:0'],
            'assignee_user_ids' => [$required, 'array', 'min:1'],
            'assignee_user_ids.*' => ['uuid'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
