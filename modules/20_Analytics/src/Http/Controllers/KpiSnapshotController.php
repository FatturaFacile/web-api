<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Analytics\Models\KpiSnapshot;
use Modules\Analytics\Services\KpiCatalog;
use Modules\Analytics\Services\KpiSnapshotService;
use OpenApi\Attributes as OA;

/**
 * Daily snapshots of the heaviest KPIs, read from a table instead of recalculated: the fast source for dashboards.
 */
final class KpiSnapshotController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly KpiSnapshotService $snapshots, private readonly KpiCatalog $catalog, private readonly TenantClock $clock) {}

    /**
     * The stored series of the KPIs.
     */
    #[OA\Get(
        path: '/api/v1/analytics/kpi-snapshots',
        operationId: 'listAnalyticsKpiSnapshots',
        description: 'Daily values materialised by the nightly refresh (`analytics:refresh-kpi-snapshots`). Period KPIs hold the month to date at the snapshot day, aging and stock the position when it was taken. Nothing is calculated by this call. KPIs drawn from a module the caller cannot read are left out; asking for one of them with `kpi` answers 403.',
        summary: 'List KPI snapshots',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [
            new OA\Parameter(name: 'kpi', description: 'One KPI key (default: every snapshotted KPI the caller can read)', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'sales')),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodTo'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Snapshots, oldest first', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsKpiSnapshot'))])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kpi' => ['nullable', 'string', Rule::in($this->snapshots->keys())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = $this->clock->today();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $today;
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(29);
        abort_if($from->diffInDays($to) > 400, 422, 'The range cannot exceed 400 days.');

        $keys = isset($data['kpi']) ? [$data['kpi']] : $this->snapshots->keys();
        $rows = [];

        foreach ($keys as $key) {
            if (! $this->catalog->canRead($this->catalog->calculator($key), $request->user())) {
                if (isset($data['kpi'])) {
                    throw new AuthorizationException('Your role cannot read the data this indicator is drawn from.');
                }

                continue;
            }

            foreach ($this->snapshots->series($key, $from, $to) as $snapshot) {
                $rows[] = $this->present($snapshot);
            }
        }

        return $this->successResponse($rows, 'KPI snapshots retrieved');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(KpiSnapshot $snapshot): array
    {
        return [
            'kpi' => $snapshot->kpi_key,
            'snapshot_date' => $snapshot->snapshot_date->toDateString(),
            'period_from' => $snapshot->period_from->toDateString(),
            'period_to' => $snapshot->period_to->toDateString(),
            'value' => $snapshot->value,
            'details' => $snapshot->details,
            'computed_at' => $snapshot->computed_at->toIso8601String(),
        ];
    }
}
