<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Application\Queries\GetCompetitorHistoryHandler;
use Modules\TenderManagement\Application\Queries\GetCompetitorHistoryQuery;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Http\Requests\ListFiltersRequest;
use Modules\TenderManagement\Http\Requests\SaveBenchmarkRequest;
use Modules\TenderManagement\Http\Resources\BenchmarkResource;
use Modules\TenderManagement\Models\CompetitorBenchmark;
use Modules\TenderManagement\Services\BenchmarkService;
use OpenApi\Attributes as OA;

/**
 * The competitor and award history archive.
 */
final class BenchmarkController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Get(path: '/api/v1/tender-management/benchmarks', operationId: 'tenderManagementListBenchmarks', summary: 'Competitor award history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Benchmarks, paginated')])]
    public function index(ListFiltersRequest $request, GetCompetitorHistoryHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', CompetitorBenchmark::class);

        $page = $handler->handle(new GetCompetitorHistoryQuery(
            search: $request->input('q'),
            contractingAuthorityName: $request->input('contracting_authority_name'),
            cpvCode: $request->input('cpv_code'),
            jurisdiction: $request->input('jurisdiction'),
            winnersOnly: $request->boolean('winners_only'),
            perPage: $this->perPage(),
        ));

        return $this->paged($page, BenchmarkResource::class, $this->message('listed'));
    }

    #[OA\Get(path: '/api/v1/tender-management/benchmarks/statistics', operationId: 'tenderManagementBenchmarkStatistics', summary: 'Discount and score statistics by contracting authority and CPV', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Statistics')])]
    public function statistics(ListFiltersRequest $request, BenchmarkService $benchmarks): JsonResponse
    {
        Gate::authorize('viewAny', CompetitorBenchmark::class);

        return $this->successResponse($benchmarks->statistics($request->input('contracting_authority_name'), $request->input('cpv_code')));
    }

    #[OA\Post(path: '/api/v1/tender-management/benchmarks', operationId: 'tenderManagementCreateBenchmark', summary: 'Record a competitor award', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 201, description: 'Benchmark created')])]
    public function store(SaveBenchmarkRequest $request): JsonResponse
    {
        Gate::authorize('create', CompetitorBenchmark::class);

        $benchmark = CompetitorBenchmark::create(array_merge($request->validated(), ['created_by' => $this->userId()]));

        return $this->successResponse(new BenchmarkResource($benchmark->refresh()), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/tender-management/benchmarks/{id}', operationId: 'tenderManagementUpdateBenchmark', summary: 'Update a competitor award', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Benchmark updated')])]
    public function update(SaveBenchmarkRequest $request, string $id): JsonResponse
    {
        $benchmark = CompetitorBenchmark::query()->findOrFail($id);
        Gate::authorize('update', $benchmark);
        $benchmark->fill(array_merge($request->validated(), ['updated_by' => $this->userId()]))->save();

        return $this->successResponse(new BenchmarkResource($benchmark->refresh()), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/tender-management/benchmarks/{id}', operationId: 'tenderManagementDeleteBenchmark', summary: 'Delete a competitor award', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Benchmark deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $benchmark = CompetitorBenchmark::query()->findOrFail($id);
        Gate::authorize('delete', $benchmark);
        $benchmark->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
