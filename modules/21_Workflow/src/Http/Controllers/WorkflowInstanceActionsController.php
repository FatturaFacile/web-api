<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowExportService;
use Modules\Workflow\Services\WorkflowLifecycleService;
use Modules\Workflow\Services\WorkflowTimelineService;
use OpenApi\Attributes as OA;

/**
 * What happens around a running approval: the requester withdraws it, the timeline tells its story and the evidence PDF
 * documents it for the file.
 */
final class WorkflowInstanceActionsController extends Controller
{
    use ApiResponse;

    /**
     * The requester takes the approval back while nobody has decided.
     */
    #[OA\Post(
        path: '/api/v1/workflows/instances/{id}/withdraw',
        operationId: 'withdrawWorkflowsInstance',
        description: 'The requester withdraws an approval as long as no approver has decided. A decision already taken answers 409.',
        summary: 'Withdraw a workflow instance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Withdrawn', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Only the requester can withdraw', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already decided or ended', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Withdraw a workflow instance', tags: ['Workflow Engine'])]
    public function withdraw(string $id, Request $request, WorkflowLifecycleService $lifecycle): JsonResponse
    {
        $instance = WorkflowInstance::query()->findOrFail($id);
        $this->authorize('withdraw', $instance);

        $instance = $lifecycle->withdraw($instance, (string) $request->user()->id);

        return $this->successResponse(['id' => $instance->id, 'status' => $instance->status], 'Workflow instance withdrawn');
    }

    /**
     * The story of the approval, step by step.
     */
    #[OA\Get(
        path: '/api/v1/workflows/instances/{id}/timeline',
        operationId: 'getTimelineWorkflowsInstance',
        description: 'Steps and lifecycle events of an approval in time order: who had to decide, when, what they decided, through which channel and why.',
        summary: 'Instance timeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Timeline', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Instance timeline', tags: ['Workflow Engine'])]
    public function timeline(string $id, WorkflowTimelineService $timeline): JsonResponse
    {
        $instance = WorkflowInstance::query()->findOrFail($id);
        $this->authorize('view', $instance);

        return $this->successResponse($timeline->timeline($instance), 'Timeline retrieved');
    }

    /**
     * "Evidenza di approvazione" as a PDF.
     */
    #[OA\Get(
        path: '/api/v1/workflows/instances/{id}/evidence',
        operationId: 'getEvidenceWorkflowsInstance',
        description: 'The approval evidence of one instance as a PDF with date, time, identity, channel and reason of every step, to attach to the document and archive in the DMS.',
        summary: 'Approval evidence PDF',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF file', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Approval evidence PDF', tags: ['Workflow Engine'])]
    public function evidence(string $id, WorkflowExportService $exports): Response
    {
        $instance = WorkflowInstance::query()->findOrFail($id);
        $this->authorize('viewAuditTrail', $instance);

        return response($exports->evidencePdf($instance, app()->getLocale()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$exports->evidenceFileName($instance).'"',
        ]);
    }
}
