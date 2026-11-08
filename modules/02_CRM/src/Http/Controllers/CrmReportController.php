<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Services\CrmReportService;
use OpenApi\Attributes as OA;

/**
 * Sales management reports: pipeline, conversion and activity statistics of the active tenant.
 */
final class CrmReportController extends Controller
{
    public function __construct(
        private readonly CrmReportService $reports,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Open pipeline by stage and by owner.
     */
    #[OA\Get(
        path: '/api/v1/crm/reports/pipeline',
        operationId: 'getCrmPipelineReport',
        description: 'Open pipeline of the active tenant by stage (in pipeline order) and by owner: number of opportunities, amount and probability-weighted amount. from/to filter on the expected close date.',
        summary: 'Pipeline report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Expected close date from', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Expected close date to', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'owner_user_id', in: 'query', required: false, description: 'Only this owner', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pipeline report', content: new OA\JsonContent(ref: '#/components/schemas/CRMPipelineReport')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function pipeline(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'owner_user_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->reports->pipeline((string) $this->tenantContext->getTenantId(), $filters));
    }

    /**
     * Lead and opportunity conversion.
     */
    #[OA\Get(
        path: '/api/v1/crm/reports/conversion',
        operationId: 'getCrmConversionReport',
        description: 'Lead funnel (by status, qualification and conversion rates, average days to convert, by source) for leads created in the period, and opportunity win rate for deals closed in the period.',
        summary: 'Conversion report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Period start', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Period end', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conversion report', content: new OA\JsonContent(ref: '#/components/schemas/CRMConversionReport')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function conversion(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json($this->reports->conversion((string) $this->tenantContext->getTenantId(), $filters));
    }

    /**
     * Activity statistics.
     */
    #[OA\Get(
        path: '/api/v1/crm/reports/activities',
        operationId: 'getCrmActivityReport',
        description: 'Activities created in the period by status, type and assignee, with completion rate and overdue backlog.',
        summary: 'Activity report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Period start', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Period end', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'assigned_user_id', in: 'query', required: false, description: 'Only this assignee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Activity report', content: new OA\JsonContent(ref: '#/components/schemas/CRMActivityReport')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function activities(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'assigned_user_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->reports->activities((string) $this->tenantContext->getTenantId(), $filters));
    }
}
