<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\MarginQueryRequest;
use Modules\Controlling\Models\SalesMarginFact;
use Modules\Controlling\Services\MarginExporter;
use Modules\Controlling\Services\MultidimensionalMarginService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multidimensional margin control (#216): summary, explorer with drill-down, cross-tab, Pareto and export.
 */
final class MarginController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly MultidimensionalMarginService $margins, private readonly MarginExporter $exporter) {}

    public function summary(MarginQueryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SalesMarginFact::class);

        return response()->json(['data' => $this->domain(fn (): array => $this->margins->summary($this->tenantId(), $request->validated()))]);
    }

    public function explore(MarginQueryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SalesMarginFact::class);

        return response()->json($this->domain(fn (): array => $this->margins->explore($this->tenantId(), $request->validated())));
    }

    public function matrix(MarginQueryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SalesMarginFact::class);

        return response()->json(['data' => $this->domain(fn (): array => $this->margins->matrix($this->tenantId(), $request->validated()))]);
    }

    public function pareto(MarginQueryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SalesMarginFact::class);

        return response()->json(['data' => $this->domain(fn (): array => $this->margins->pareto($this->tenantId(), $request->validated()))]);
    }

    public function export(MarginQueryRequest $request): Response
    {
        $this->authorize('viewAny', SalesMarginFact::class);
        $query = $request->validated();
        $rows = $this->domain(fn (): array => $this->margins->exportRows($this->tenantId(), $query));
        $name = 'margins-'.($query['group_by'] ?? 'product').'-'.now()->format('Ymd');

        return ($query['format'] ?? 'csv') === 'xlsx' ? $this->exporter->xlsx($rows, $name.'.xlsx') : $this->exporter->csv($rows, $name.'.csv');
    }
}
