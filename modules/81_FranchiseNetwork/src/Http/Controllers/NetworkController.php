<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Domain\Support\LocalizedText;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\PeriodRequest;
use Modules\FranchiseNetwork\Models\BenchmarkDimension;
use Modules\FranchiseNetwork\Models\BenchmarkNetworkStat;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Services\BenchmarkService;
use Modules\FranchiseNetwork\Services\NetworkDashboardService;
use OpenApi\Attributes as OA;

/**
 * Franchisor command view: network KPIs and the network benchmark statistics.
 */
final class NetworkController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly NetworkDashboardService $dashboard, private readonly BenchmarkService $benchmark) {}

    #[OA\Get(path: '/api/v1/franchise-network/dashboard', operationId: 'franchiseNetworkDashboard', summary: 'Network KPIs of the franchisor for a month', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(): JsonResponse
    {
        Gate::authorize('manage', FranchiseStore::class);

        return $this->successResponse($this->dashboard->overview($this->period()), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/benchmark/snapshot', operationId: 'franchiseNetworkBenchmarkSnapshot', summary: 'Compute the KPI snapshot of the stores and the anonymous network statistics of a month', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Snapshot computed')])]
    public function snapshot(PeriodRequest $request): JsonResponse
    {
        Gate::authorize('create', BenchmarkNetworkStat::class);

        return $this->successResponse($this->benchmark->snapshot((string) $request->validated('period')), $this->message('benchmark_computed'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/benchmark/stats', operationId: 'franchiseNetworkBenchmarkStats', summary: 'Anonymous average, median and top quartile of the network and of each size cluster', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Statistics')])]
    public function stats(): JsonResponse
    {
        Gate::authorize('manage', BenchmarkNetworkStat::class);
        $locale = app()->getLocale();
        $dimensions = BenchmarkDimension::query()->orderBy('sort_order')->get()->keyBy('code');

        return $this->successResponse([
            'period' => $this->period(),
            'rows' => BenchmarkNetworkStat::query()->where('period', $this->period())->orderBy('cluster')->get()
                ->map(static fn (BenchmarkNetworkStat $stat): array => [
                    'cluster' => $stat->cluster,
                    'metric' => $stat->metric,
                    'label' => LocalizedText::pick($dimensions->get($stat->metric)?->labels, $locale),
                    'average' => $stat->average, 'median' => $stat->median, 'p75' => $stat->p75, 'sample_size' => $stat->sample_size,
                ])->all(),
        ], $this->message('loaded'));
    }
}
