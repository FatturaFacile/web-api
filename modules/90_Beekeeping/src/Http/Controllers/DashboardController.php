<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Beekeeping\Application\Queries\GetApiaryMapHandler;
use Modules\Beekeeping\Application\Queries\GetApiaryMapQuery;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\VarroaTreatment;
use OpenApi\Attributes as OA;

/**
 * Overview figures and the data of the GIS map (pins, 3 km foraging circles and their overlaps).
 */
final class DashboardController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly GetApiaryMapHandler $map) {}

    #[OA\Get(path: '/api/v1/beekeeping/dashboard', operationId: 'beekeepingDashboard', summary: 'Overview figures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function index(): JsonResponse
    {
        $this->authorizeRead();
        $today = CarbonImmutable::today()->toDateString();

        return $this->successResponse([
            'apiaries' => Apiary::query()->where('is_active', true)->count(),
            'hives' => Hive::query()->where('is_nucleus', false)->whereIn('status', ['active', 'orphaned', 'queenless_cell'])->count(),
            'nuclei' => Hive::query()->where('is_nucleus', true)->whereIn('status', ['active', 'orphaned', 'queenless_cell'])->count(),
            'orphaned_hives' => Hive::query()->whereIn('status', ['orphaned', 'queenless_cell'])->count(),
            'active_withdrawals' => VarroaTreatment::query()->whereDate('treatment_date', '<=', $today)->whereDate('withdrawal_end_date', '>', $today)->count(),
            'batches_in_maturation' => ExtractionBatch::query()->whereNull('moisture_pct')->count(),
        ]);
    }

    #[OA\Get(path: '/api/v1/beekeeping/map', operationId: 'beekeepingMap', summary: 'Apiary pins, foraging circles and overlaps', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Map data')])]
    public function map(): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse($this->map->handle(new GetApiaryMapQuery));
    }
}
