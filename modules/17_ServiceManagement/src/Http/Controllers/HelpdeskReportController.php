<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Http\Requests\HelpdeskReportRequest;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskReportService;
use OpenApi\Attributes as OA;

/**
 * Helpdesk reporting of the active tenant.
 */
final class HelpdeskReportController extends Controller
{
    #[OA\Get(path: '/api/v1/esm/helpdesk/reports', operationId: 'getEsmHelpdeskReport', description: 'Returns the helpdesk figures of the tenant for a period (default: the last 30 days): SLA compliance of first response and resolution, first response and resolution times (mean, median, 90th percentile, in minutes), backlog by status and age, volume by channel and category, agent workload and CSAT by team. Service managers only.', summary: 'Helpdesk report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Reports'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPeriodFrom'), new OA\Parameter(ref: '#/components/parameters/HelpdeskPeriodTo')], responses: [
        new OA\Response(response: 200, description: 'Report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskReport')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function show(HelpdeskReportRequest $request, HelpdeskReportService $reports, TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('viewReports', HelpdeskSetting::class);

        $to = CarbonImmutable::parse($request->validated('to') ?? now()->toDateString());
        $from = CarbonImmutable::parse($request->validated('from') ?? $to->subDays(29)->toDateString());

        return response()->json(['data' => $reports->report((string) $tenantContext->getTenantId(), $from, $to)]);
    }
}
