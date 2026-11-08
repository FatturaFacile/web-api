<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CRM\Http\Requests\SaveLeadScoringRuleRequest;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadScoringRule;
use Modules\CRM\Services\LeadScoringService;
use OpenApi\Attributes as OA;

/**
 * Tenant lead scoring model: rule management, bulk recalculation and the per-lead explanation.
 */
final class LeadScoringController extends Controller
{
    public function __construct(
        private readonly LeadScoringService $scoringService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Lists the scoring rules of the tenant in evaluation order.
     */
    #[OA\Get(
        path: '/api/v1/crm/lead-scoring-rules',
        operationId: 'listCrmLeadScoringRules',
        description: 'Lists the lead scoring rules of the active tenant in evaluation order. Without active rules, leads are scored by the built-in model.',
        summary: 'List lead scoring rules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Scoring'],
        responses: [
            new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMLeadScoringRule'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $rules = LeadScoringRule::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('sequence')->orderBy('created_at')->get();

        return response()->json(['data' => $rules->map(fn (LeadScoringRule $rule): array => $this->present($rule))->values()]);
    }

    /**
     * Creates a scoring rule.
     */
    #[OA\Post(
        path: '/api/v1/crm/lead-scoring-rules',
        operationId: 'createCrmLeadScoringRule',
        description: 'Creates a lead scoring rule. Field rules add points when a lead attribute satisfies the operator; activity rules add points per logged activity of the type, capped by max_points. Call the recalculate endpoint to apply the new model to existing leads.',
        summary: 'Create lead scoring rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Scoring'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSaveLeadScoringRuleRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadScoringRule')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveLeadScoringRuleRequest $request): JsonResponse
    {
        $rule = LeadScoringRule::create([
            ...$request->validated(),
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'sequence' => $request->integer('sequence', 10),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($this->present($rule), 201);
    }

    /**
     * Updates a scoring rule.
     */
    #[OA\Put(
        path: '/api/v1/crm/lead-scoring-rules/{id}',
        operationId: 'updateCrmLeadScoringRule',
        description: 'Updates a lead scoring rule of the active tenant. Also accepts the PATCH method.',
        summary: 'Update lead scoring rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Scoring'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the rule', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSaveLeadScoringRuleRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadScoringRule')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveLeadScoringRuleRequest $request, string $id): JsonResponse
    {
        $rule = LeadScoringRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $rule->update($request->validated());

        return response()->json($this->present($rule));
    }

    /**
     * Deletes a scoring rule.
     */
    #[OA\Delete(
        path: '/api/v1/crm/lead-scoring-rules/{id}',
        operationId: 'deleteCrmLeadScoringRule',
        description: 'Deletes a lead scoring rule of the active tenant.',
        summary: 'Delete lead scoring rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Scoring'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the rule', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        LeadScoringRule::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * Rescores every lead of the tenant (or one lead) with the current model.
     */
    #[OA\Post(
        path: '/api/v1/crm/lead-scoring-rules/recalculate',
        operationId: 'recalculateCrmLeadScores',
        description: 'Recalculates and stores the score, rating and explanation of every lead of the active tenant, or of the lead given in lead_id.',
        summary: 'Recalculate lead scores',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Scoring'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Leads rescored', content: new OA\JsonContent(properties: [new OA\Property(property: 'recalculated', type: 'integer')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function recalculate(Request $request): JsonResponse
    {
        $request->validate(['lead_id' => ['nullable', 'uuid']]);
        $tenantId = (string) $this->tenantContext->getTenantId();

        if ($request->filled('lead_id')) {
            $this->scoringService->recalculate(Lead::where('tenant_id', $tenantId)->findOrFail($request->input('lead_id')));

            return response()->json(['recalculated' => 1]);
        }

        return response()->json(['recalculated' => $this->scoringService->recalculateTenant($tenantId)]);
    }

    /**
     * Explains the stored score of a lead and shows the score under the current model.
     */
    #[OA\Get(
        path: '/api/v1/crm/leads/{id}/score',
        operationId: 'explainCrmLeadScore',
        description: 'Returns the stored score of a lead with the points of every rule that contributed, and the score the current model would give now.',
        summary: 'Explain lead score',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Lead Scoring'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Score explanation', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadScoreExplanation')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function explain(string $id): JsonResponse
    {
        $lead = Lead::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $current = $this->scoringService->evaluate($lead);
        $stored = is_array($lead->score_breakdown) ? $lead->score_breakdown : [];

        return response()->json([
            'lead_id' => (string) $lead->id,
            'score' => (int) $lead->score,
            'rating' => $lead->rating,
            'model' => $stored['model'] ?? null,
            'breakdown' => $stored['items'] ?? [],
            'calculated_at' => $lead->score_calculated_at?->toIso8601String(),
            'current' => $current,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LeadScoringRule $rule): array
    {
        return [
            'id' => (string) $rule->id,
            'name' => $rule->name,
            'rule_type' => $rule->rule_type,
            'field' => $rule->field,
            'operator' => $rule->operator,
            'value' => $rule->value,
            'activity_type' => $rule->activity_type,
            'activity_status' => $rule->activity_status,
            'points' => $rule->points,
            'max_points' => $rule->max_points,
            'sequence' => $rule->sequence,
            'is_active' => $rule->is_active,
        ];
    }
}
