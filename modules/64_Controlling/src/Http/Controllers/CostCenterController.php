<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\CostCenterRequest;
use Modules\Controlling\Http\Resources\CostCenterResource;
use Modules\Controlling\Models\AnalyticalCostCenter;
use Modules\Controlling\Services\CostCenterReportService;

/**
 * Hierarchical analytical cost centers: flat list, rollup tree with the period results, and CRUD.
 */
final class CostCenterController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly CostCenterReportService $reports) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $this->authorize('viewAny', AnalyticalCostCenter::class);
        if ($request->query('view') === 'tree') {
            $filters = $request->validate(['fiscal_year' => ['required', 'integer', 'between:2000,2100'], 'from_month' => ['nullable', 'integer', 'between:1,12'], 'to_month' => ['nullable', 'integer', 'between:1,12']]);

            return response()->json(['data' => $this->reports->tree($this->tenantId(), (int) $filters['fiscal_year'], (int) ($filters['from_month'] ?? 1), (int) ($filters['to_month'] ?? 12))]);
        }

        return CostCenterResource::collection(AnalyticalCostCenter::query()->where('tenant_id', $this->tenantId())->orderBy('code')->paginate($this->perPage()));
    }

    public function store(CostCenterRequest $request): JsonResponse
    {
        $this->authorize('create', AnalyticalCostCenter::class);
        $center = AnalyticalCostCenter::create(['tenant_id' => $this->tenantId(), 'is_active' => true, ...$request->validated()]);

        return (new CostCenterResource($center))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): CostCenterResource
    {
        $center = AnalyticalCostCenter::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $center);

        return new CostCenterResource($center);
    }

    public function update(CostCenterRequest $request, string $id): CostCenterResource
    {
        $center = AnalyticalCostCenter::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $center);
        $center->update($request->validated());

        return new CostCenterResource($center);
    }

    public function destroy(string $id): Response
    {
        $center = AnalyticalCostCenter::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $center);
        if ($center->children()->exists()) {
            throw ValidationException::withMessages(['request' => ['A cost center with children cannot be deleted.']]);
        }
        $center->delete();

        return response()->noContent();
    }
}
