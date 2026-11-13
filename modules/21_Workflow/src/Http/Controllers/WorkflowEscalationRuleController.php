<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Http\Requests\SaveEscalationRuleRequest;
use Modules\Workflow\Models\WorkflowEscalationRule;
use Modules\Workflow\Models\WorkflowNode;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Escalation rules of the approvals of the company. A rule is attached to the nodes of a definition
 * (`escalation_rule_id`): it reminds whoever has a step pending, escalates it to a manager, a role or a user, and can
 * decide automatically when the time limit runs out.
 */
final class WorkflowEscalationRuleController extends Controller
{
    use ApiResponse;

    /**
     * List rules.
     */
    #[OA\Get(
        path: '/api/v1/workflows/escalation-rules',
        operationId: 'listWorkflowEscalationRules',
        description: 'Escalation rules of the active company, by name. Every member can read them; owners and administrators change them.',
        summary: 'List escalation rules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Escalation Rules'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Rows per page (1-100)', schema: new OA\Schema(type: 'integer', default: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowEscalationRule'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $rules = WorkflowEscalationRule::query()->orderBy('name')->gridPaginate(max(1, min(100, (int) $request->query('per_page', 100))));

        return $this->successResponse(
            $rules->getCollection()->map(fn (WorkflowEscalationRule $rule): array => $this->present($rule))->all(),
            'Escalation rules retrieved',
            meta: ['current_page' => $rules->currentPage(), 'last_page' => $rules->lastPage(), 'per_page' => $rules->perPage(), 'total' => $rules->total()]
        );
    }

    /**
     * Create a rule.
     */
    #[OA\Post(
        path: '/api/v1/workflows/escalation-rules',
        operationId: 'createWorkflowEscalationRule',
        description: 'Creates a rule. Hours count in business hours of the calendar of the node the rule is attached to, from the moment the step opens. The escalation must come after the reminder.',
        summary: 'Create an escalation rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveEscalationRuleRequest')),
        tags: ['Workflow Escalation Rules'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkflowEscalationRule')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveEscalationRuleRequest $request): JsonResponse
    {
        $rule = WorkflowEscalationRule::create($this->attributes($request));

        return $this->successResponse($this->present($rule), 'Escalation rule created', Response::HTTP_CREATED);
    }

    /**
     * Update a rule.
     */
    #[OA\Put(
        path: '/api/v1/workflows/escalation-rules/{id}',
        operationId: 'updateWorkflowEscalationRule',
        description: 'Changes a rule. Steps already pending keep the reminder and escalation they already got; the new times apply to the ones that have not happened yet.',
        summary: 'Update an escalation rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveEscalationRuleRequest')),
        tags: ['Workflow Escalation Rules'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkflowEscalationRule')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveEscalationRuleRequest $request, string $id): JsonResponse
    {
        $rule = WorkflowEscalationRule::query()->findOrFail($id);
        $rule->update($this->attributes($request));

        return $this->successResponse($this->present($rule), 'Escalation rule updated');
    }

    /**
     * Delete a rule.
     */
    #[OA\Delete(
        path: '/api/v1/workflows/escalation-rules/{id}',
        operationId: 'deleteWorkflowEscalationRule',
        description: 'Deletes a rule that no node uses.',
        summary: 'Delete an escalation rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Escalation Rules'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'A node still uses the rule', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $rule = WorkflowEscalationRule::query()->findOrFail($id);

        if (WorkflowNode::query()->where('escalation_rule_id', $rule->id)->exists()) {
            throw new ConflictHttpException('A workflow node still uses this rule: detach it first.');
        }

        $rule->delete();

        return $this->successResponse(null, 'Escalation rule deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SaveEscalationRuleRequest $request): array
    {
        $data = $request->validated();
        $data['auto_action'] ??= 'none';
        $data['escalate_to_id'] = $data['escalate_to_type'] === 'reporting_manager' ? null : ($data['escalate_to_id'] ?? null);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WorkflowEscalationRule $rule): array
    {
        return [
            'id' => (string) $rule->id,
            'name' => $rule->name,
            'reminder_hours' => (int) $rule->reminder_hours,
            'escalation_hours' => (int) $rule->escalation_hours,
            'escalate_to_type' => $rule->escalate_to_type,
            'escalate_to_id' => $rule->escalate_to_id,
            'auto_action' => $rule->auto_action ?? 'none',
        ];
    }
}
