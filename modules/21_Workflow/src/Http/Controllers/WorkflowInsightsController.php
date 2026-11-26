<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\ApprovableRegistry;
use Modules\Workflow\Services\WorkflowExportService;
use Modules\Workflow\Services\WorkflowInboxService;
use Modules\Workflow\Services\WorkflowSlaReportService;
use OpenApi\Attributes as OA;

/**
 * Small read endpoints around the inbox: the counter of the header badge, the subject types the designer offers and the
 * downloadable SLA report.
 */
final class WorkflowInsightsController extends Controller
{
    use ApiResponse;

    /**
     * Pending and overdue approvals of the user, for the header badge.
     */
    #[OA\Get(
        path: '/api/v1/workflows/pending-count',
        operationId: 'getPendingCountWorkflows',
        description: 'How many approvals wait for the user (own, by role and delegated) and how many of them are overdue.',
        summary: 'Pending approvals counter',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Inbox'],
        responses: [
            new OA\Response(response: 200, description: 'Counters', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Pending approvals counter', tags: ['Workflow Engine'])]
    public function pendingCount(Request $request, WorkflowInboxService $inbox): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'pending_count' => $inbox->tasks($user)->count(),
            'overdue_count' => $inbox->tasks($user, ['state' => 'overdue'])->count(),
        ], 'Pending approvals counted');
    }

    /**
     * Subject types that can have a workflow.
     */
    #[OA\Get(
        path: '/api/v1/workflows/subject-types',
        operationId: 'listSubjectTypesWorkflows',
        description: 'The document types the approval engine knows, with their module and whether a consumer class is registered.',
        summary: 'List approvable subject types',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        responses: [
            new OA\Response(response: 200, description: 'Subject types', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List approvable subject types', tags: ['Workflow Engine'])]
    public function subjectTypes(ApprovableRegistry $registry): JsonResponse
    {
        return $this->successResponse(array_map(static fn (array $subject): array => [
            'code' => $subject['code'],
            'module' => $subject['module'],
            'registered' => $subject['registered'],
        ], $registry->subjectTypes()), 'Subject types retrieved');
    }

    /**
     * The SLA report as XLSX or PDF.
     */
    #[OA\Get(
        path: '/api/v1/workflows/sla-report/export',
        operationId: 'exportSlaReportWorkflows',
        description: 'The SLA report of the period (average time per definition, node and approver, overdue items, bottlenecks) as an XLSX workbook or a PDF. Owners and administrators only.',
        summary: 'Export the SLA report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Inbox'],
        parameters: [
            new OA\Parameter(name: 'format', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File', content: new OA\MediaType(mediaType: 'application/octet-stream')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid period or format', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Export the SLA report', tags: ['Workflow Engine'])]
    public function slaExport(Request $request, WorkflowSlaReportService $report, WorkflowExportService $exports, TenantClock $clock): Response
    {
        $this->authorize('viewSla', WorkflowInstance::class);

        $validated = $request->validate([
            'format' => ['required', 'in:xlsx,pdf'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $clock->today();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $to->subDays(30);

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => __('workflow_errors.inbox.period_too_long')]);
        }

        $data = $report->report($from, $to);
        $xlsx = $validated['format'] === 'xlsx';

        return response($xlsx ? $exports->slaXlsx($data, app()->getLocale()) : $exports->slaPdf($data, app()->getLocale()), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="workflow-sla-'.$from->toDateString().'-'.$to->toDateString().'.'.$validated['format'].'"',
        ]);
    }
}
