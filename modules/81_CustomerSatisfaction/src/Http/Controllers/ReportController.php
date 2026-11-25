<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Http\Requests\GenerateReportRequest;
use Modules\CustomerSatisfaction\Http\Requests\ListFiltersRequest;
use Modules\CustomerSatisfaction\Http\Resources\Iso9001ReportResource;
use Modules\CustomerSatisfaction\Models\Iso9001SatisfactionReport;
use Modules\CustomerSatisfaction\Services\Iso9001ReportService;
use OpenApi\Attributes as OA;

/**
 * ISO 9001:2015 § 9.1.2 Management Review snapshots and their JSON / CSV / printable HTML export.
 */
final class ReportController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/customer-satisfaction/reports', operationId: 'customerSatisfactionListReports', summary: 'List the saved ISO 9001 satisfaction reports', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Reports, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Iso9001SatisfactionReport::class);

        return $this->paged(Iso9001SatisfactionReport::query()->latest('created_at')->paginate($this->perPage()), Iso9001ReportResource::class);
    }

    #[OA\Post(path: '/api/v1/customer-satisfaction/reports', operationId: 'customerSatisfactionGenerateReport', summary: 'Freeze the analytics of a period into a Management Review report', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 201, description: 'Report generated')])]
    public function store(GenerateReportRequest $request, Iso9001ReportService $reports): JsonResponse
    {
        Gate::authorize('create', Iso9001SatisfactionReport::class);
        $report = $reports->generate($request->validated(), $this->userId());

        return $this->successResponse(new Iso9001ReportResource($report), $this->message('report_generated'), 201);
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/reports/{id}', operationId: 'customerSatisfactionShowReport', summary: 'Show a report with its metrics snapshot', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Report')])]
    public function show(string $id): JsonResponse
    {
        $report = Iso9001SatisfactionReport::query()->findOrFail($id);
        Gate::authorize('view', $report);

        return $this->successResponse(new Iso9001ReportResource($report), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/reports/{id}/export', operationId: 'customerSatisfactionExportReport', summary: 'Download a report as JSON, CSV or printable HTML', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv', 'html']))], responses: [new OA\Response(response: 200, description: 'Report file')])]
    public function export(Request $request, Iso9001ReportService $reports, string $id): Response
    {
        $report = Iso9001SatisfactionReport::query()->findOrFail($id);
        Gate::authorize('view', $report);

        $file = $reports->export($report, (string) $request->query('format', 'html'));

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }
}
