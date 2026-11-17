<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Models\LeadRoutingRule;
use OpenApi\Attributes as OA;

/**
 * CRUD for the automatic lead assignment rules (geographic, product line, company size, round-robin).
 */
final class LeadRoutingRuleController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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
    public function index(): JsonResponse
    {
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
        $rule = LeadRoutingRule::create(['tenant_id' => $this->tenantContext->getTenantId()] + $this->validated($request));

        return response()->json($rule, 201);
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
    public function destroy(string $id): JsonResponse
    {
        LeadRoutingRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id)->delete();

        return response()->json(null, 204);
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
