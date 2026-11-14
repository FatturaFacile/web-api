<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Services\InventoryService;
use Modules\Sustainability\Support\ActivityCatalog;
use OpenApi\Attributes as OA;

/**
 * The GHG inventory report of a reporting period and the catalogue of activity types.
 */
final class InventoryController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly InventoryService $inventory) {}

    #[OA\Get(path: '/api/v1/sustainability/inventory', operationId: 'getEsgInventory', description: 'The GHG inventory of a reporting period (default: the most recent): CO2e by scope and category with shares, the share of estimated data, the intensity per million of revenue and per FTE, and the change against the previous period.', summary: 'GHG inventory report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [
        new OA\Parameter(name: 'reporting_period_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Inventory (data is null when the company has no reporting period)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityInventory', nullable: true)])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EsgReportingPeriod::class);
        $filters = $request->validate(['reporting_period_id' => ['nullable', 'uuid']]);

        $query = EsgReportingPeriod::query()->where('tenant_id', $this->tenantId());
        $period = isset($filters['reporting_period_id']) ? $query->findOrFail($filters['reporting_period_id']) : $query->orderByDesc('starts_on')->first();

        return response()->json(['data' => $period === null ? null : $this->inventory->report($period)]);
    }

    #[OA\Get(path: '/api/v1/sustainability/catalog', operationId: 'getEsgCatalog', description: 'The activity types with their GHG Protocol scope, reporting category and units, plus the regions and the data quality flags the API accepts.', summary: 'ESG catalogue', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Catalogue', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityCatalog')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function catalog(): JsonResponse
    {
        $this->authorize('viewAny', EsgReportingPeriod::class);

        $types = [];
        foreach (ActivityCatalog::Types as $type => $definition) {
            $types[] = ['activity_type' => $type, ...$definition];
        }

        return response()->json(['data' => [
            'activity_types' => $types,
            'regions' => ActivityCatalog::Regions,
            'data_qualities' => ActivityCatalog::DataQualities,
        ]]);
    }
}
