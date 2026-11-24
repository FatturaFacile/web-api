<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Workflow\Http\Requests\SaveWorkflowNodeRequest;
use Modules\Workflow\Http\Requests\SaveWorkflowTransitionRequest;
use Modules\Workflow\Http\Resources\WorkflowNodeResource;
use Modules\Workflow\Http\Resources\WorkflowTransitionResource;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Modules\Workflow\Services\WorkflowDefinitionManager;
use OpenApi\Attributes as OA;

/**
 * Nodes (steps) and transitions of a draft workflow definition. Owner and admin only.
 */
final class WorkflowDefinitionGraphController extends Controller
{
    public function __construct(private readonly WorkflowDefinitionManager $definitions) {}

    #[OA\Post(path: '/api/v1/workflows/definitions/{id}/nodes', operationId: 'createWorkflowDefinitionNode', description: 'Adds a node (start, approval, conditional, notification or end) to a draft definition.', summary: 'Add workflow node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveNodeRequest')), responses: [
        new OA\Response(response: 201, description: 'Node added', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowNodeResource')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Definition not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeNode(SaveWorkflowNodeRequest $request, string $id): JsonResponse
    {
        $node = $this->definitions->createNode($this->definition($id), $request->validated());

        return (new WorkflowNodeResource($node))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/workflows/definitions/{id}/nodes/{nodeId}', operationId: 'updateWorkflowDefinitionNode', description: 'Changes a node of a draft definition.', summary: 'Update workflow node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId'), new OA\Parameter(ref: '#/components/parameters/WorkflowNodeId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveNodeRequest')), responses: [
        new OA\Response(response: 200, description: 'Node changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowNodeResource')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Node not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    #[OA\Patch(path: '/api/v1/workflows/definitions/{id}/nodes/{nodeId}', operationId: 'patchWorkflowDefinitionNode', description: 'Changes some fields of a node of a draft definition.', summary: 'Partially update workflow node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId'), new OA\Parameter(ref: '#/components/parameters/WorkflowNodeId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveNodeRequest')), responses: [
        new OA\Response(response: 200, description: 'Node changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowNodeResource')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Node not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateNode(SaveWorkflowNodeRequest $request, string $id, string $nodeId): WorkflowNodeResource
    {
        $definition = $this->definition($id);

        return new WorkflowNodeResource($this->definitions->updateNode($definition, $this->node($definition, $nodeId), $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/workflows/definitions/{id}/nodes/{nodeId}', operationId: 'deleteWorkflowDefinitionNode', description: 'Removes a node and the transitions that touch it from a draft definition.', summary: 'Delete workflow node', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId'), new OA\Parameter(ref: '#/components/parameters/WorkflowNodeId')], responses: [
        new OA\Response(response: 204, description: 'Node removed'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Node not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function destroyNode(string $id, string $nodeId): Response
    {
        $definition = $this->definition($id);
        $this->definitions->deleteNode($definition, $this->node($definition, $nodeId));

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/workflows/definitions/{id}/transitions', operationId: 'createWorkflowDefinitionTransition', description: 'Adds a transition between two nodes of a draft definition.', summary: 'Add workflow transition', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveTransitionRequest')), responses: [
        new OA\Response(response: 201, description: 'Transition added', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowTransitionResource')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Definition not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function storeTransition(SaveWorkflowTransitionRequest $request, string $id): JsonResponse
    {
        $transition = $this->definitions->createTransition($this->definition($id), $request->validated());

        return (new WorkflowTransitionResource($transition))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/workflows/definitions/{id}/transitions/{transitionId}', operationId: 'updateWorkflowDefinitionTransition', description: 'Changes a transition of a draft definition.', summary: 'Update workflow transition', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId'), new OA\Parameter(ref: '#/components/parameters/WorkflowTransitionId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveTransitionRequest')), responses: [
        new OA\Response(response: 200, description: 'Transition changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowTransitionResource')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Transition not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    #[OA\Patch(path: '/api/v1/workflows/definitions/{id}/transitions/{transitionId}', operationId: 'patchWorkflowDefinitionTransition', description: 'Changes some fields of a transition of a draft definition.', summary: 'Partially update workflow transition', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId'), new OA\Parameter(ref: '#/components/parameters/WorkflowTransitionId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveTransitionRequest')), responses: [
        new OA\Response(response: 200, description: 'Transition changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowTransitionResource')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Transition not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function updateTransition(SaveWorkflowTransitionRequest $request, string $id, string $transitionId): WorkflowTransitionResource
    {
        $definition = $this->definition($id);

        return new WorkflowTransitionResource($this->definitions->updateTransition($definition, $this->transition($definition, $transitionId), $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/workflows/definitions/{id}/transitions/{transitionId}', operationId: 'deleteWorkflowDefinitionTransition', description: 'Removes a transition from a draft definition.', summary: 'Delete workflow transition', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workflow Definitions'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkflowDefinitionId'), new OA\Parameter(ref: '#/components/parameters/WorkflowTransitionId')], responses: [
        new OA\Response(response: 204, description: 'Transition removed'),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Transition not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Definition not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function destroyTransition(string $id, string $transitionId): Response
    {
        $definition = $this->definition($id);
        $this->definitions->deleteTransition($definition, $this->transition($definition, $transitionId));

        return response()->noContent();
    }

    private function definition(string $id): WorkflowDefinition
    {
        return WorkflowDefinition::query()->findOrFail($id);
    }

    private function node(WorkflowDefinition $definition, string $nodeId): WorkflowNode
    {
        return WorkflowNode::query()->where('workflow_definition_id', $definition->id)->findOrFail($nodeId);
    }

    private function transition(WorkflowDefinition $definition, string $transitionId): WorkflowTransition
    {
        return WorkflowTransition::query()->where('workflow_definition_id', $definition->id)->findOrFail($transitionId);
    }
}
