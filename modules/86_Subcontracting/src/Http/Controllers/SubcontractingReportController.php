<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;
use Modules\Subcontracting\Http\Controllers\Concerns\InteractsWithSubcontracting;
use Modules\Subcontracting\Http\Requests\CrossBorderRequest;
use Modules\Subcontracting\Http\Requests\DashboardRequest;
use Modules\Subcontracting\Http\Requests\RegisterRequest;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Services\RegisterExporter;
use Modules\Subcontracting\Services\SubcontractingReportService;
use OpenApi\Attributes as OA;

/**
 * Stock at subcontractors, the statutory register "Registro delle merci in conto lavoro" and the IT/CH TPP/TPA summary.
 */
final class SubcontractingReportController extends Controller
{
    use ApiResponse;
    use InteractsWithSubcontracting;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array ExportTypes = [
        'csv' => ['text/csv; charset=UTF-8', 'csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
        'pdf' => ['application/pdf', 'pdf'],
    ];

    public function __construct(private readonly SubcontractingReportService $reports, private readonly RegisterExporter $exporter) {}

    #[OA\Get(path: '/api/v1/subcontracting/lookups', operationId: 'subcontractingLookups', summary: 'Enumerations of the subcontracting module', security: self::SECURITY, tags: ['Subcontracting'], responses: [new OA\Response(response: 200, description: 'Enumerations')])]
    public function lookups(): JsonResponse
    {
        Gate::authorize('viewAny', SubcontractingOrder::class);

        return $this->successResponse([
            'directions' => Direction::values(),
            'subcontracting_types' => SubcontractingType::values(),
            'statuses' => OrderStatus::values(),
            'customs_regimes' => CustomsProcessingRegime::values(),
            'movement_types' => LedgerMovementType::values(),
            'deadline_statuses' => DeadlineStatus::values(),
        ], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/subcontracting/dashboard', operationId: 'subcontractingStockDashboard', summary: 'Stock held by subcontractors and customers with aging and statutory deadlines', security: self::SECURITY, tags: ['Subcontracting'], responses: [new OA\Response(response: 200, description: 'KPIs, rows and totals per partner')])]
    public function dashboard(DashboardRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SubcontractingOrder::class);

        return $this->successResponse($this->reports->dashboard($this->tenantId(), $request->validated()), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/subcontracting/register', operationId: 'subcontractingStatutoryRegister', summary: 'Registro delle merci in conto lavoro (Art. 53 DPR 633/72, DPR 441/1997)', security: self::SECURITY, tags: ['Subcontracting'], responses: [new OA\Response(response: 200, description: 'Paginated register lines with the one-year deadline')])]
    public function register(RegisterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SubcontractingOrder::class);
        $filters = $request->validated();
        $page = $this->reports->register($this->tenantId(), $filters, $this->perPage(), (int) ($filters['page'] ?? 1));

        return $this->successResponse($page->items(), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Get(path: '/api/v1/subcontracting/register/export/{format}', operationId: 'exportSubcontractingRegister', summary: 'Export the statutory register as CSV, Excel or PDF', security: self::SECURITY, tags: ['Subcontracting'], parameters: [new OA\Parameter(name: 'format', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['csv', 'xlsx', 'pdf']))], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function export(RegisterRequest $request, string $format): Response
    {
        Gate::authorize('exportLedger', SubcontractingOrder::class);
        abort_unless(isset(self::ExportTypes[$format]), 404);

        $rows = $this->reports->registerRows($this->tenantId(), $request->validated());
        $content = match ($format) {
            'csv' => $this->exporter->csv($rows, $this->locale()),
            'xlsx' => $this->exporter->xlsx($rows, $this->locale(), $this->tenantId()),
            default => $this->exporter->pdf($rows, $this->locale(), $this->tenantId()),
        };

        [$mime, $extension] = self::ExportTypes[$format];

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="registro-conto-lavoro-'.now()->toDateString().'.'.$extension.'"',
        ]);
    }

    #[OA\Get(path: '/api/v1/subcontracting/cross-border', operationId: 'subcontractingCrossBorderSummary', summary: 'IT/CH TPP and TPA processing jobs with their customs declarations and balances', security: self::SECURITY, tags: ['Subcontracting'], responses: [new OA\Response(response: 200, description: 'Regimes and jobs')])]
    public function crossBorder(CrossBorderRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SubcontractingOrder::class);

        return $this->successResponse($this->reports->crossBorder($this->tenantId(), $request->validated('customs_regime')), $this->message('loaded'));
    }
}
