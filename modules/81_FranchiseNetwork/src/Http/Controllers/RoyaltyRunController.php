<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\ListFiltersRequest;
use Modules\FranchiseNetwork\Http\Requests\PeriodRequest;
use Modules\FranchiseNetwork\Http\Resources\CollectionBatchResource;
use Modules\FranchiseNetwork\Http\Resources\RunResource;
use Modules\FranchiseNetwork\Models\RoyaltyRun;
use Modules\FranchiseNetwork\Services\RoyaltyBillingService;
use OpenApi\Attributes as OA;

/**
 * Monthly royalty and marketing fund runs: calculate and review the statements, invoice them, read the collection batches.
 */
final class RoyaltyRunController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly RoyaltyBillingService $billing) {}

    #[OA\Get(path: '/api/v1/franchise-network/royalty-runs', operationId: 'franchiseNetworkListRuns', summary: 'List the monthly royalty runs', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Runs, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('manage', RoyaltyRun::class);

        return $this->paged(RoyaltyRun::query()->orderByDesc('period')->paginate($this->perPage(24)), RunResource::class);
    }

    #[OA\Get(path: '/api/v1/franchise-network/royalty-runs/{id}', operationId: 'franchiseNetworkShowRun', summary: 'Show a run with the statement of every store and its collection batches', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Run')])]
    public function show(string $id): JsonResponse
    {
        $run = RoyaltyRun::query()->with(['statements.store', 'batches'])->findOrFail($id);
        Gate::authorize('manage', RoyaltyRun::class);

        return $this->successResponse(new RunResource($run), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/royalty-runs', operationId: 'franchiseNetworkCalculateRun', summary: 'Calculate (or recalculate until invoiced) the royalty run of a month', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Run calculated')])]
    public function calculate(PeriodRequest $request): JsonResponse
    {
        Gate::authorize('create', RoyaltyRun::class);
        $run = $this->billing->calculate((string) $request->validated('period'), $this->userId());

        return $this->successResponse(new RunResource($run->load(['statements.store', 'batches'])), $this->message('run_calculated'), 201);
    }

    #[OA\Post(path: '/api/v1/franchise-network/royalty-runs/{id}/invoice', operationId: 'franchiseNetworkInvoiceRun', summary: 'Issue the invoices of a calculated run and prepare the SEPA SDD / LSV+ batches', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Run invoiced')])]
    public function invoice(string $id): JsonResponse
    {
        $run = RoyaltyRun::query()->findOrFail($id);
        Gate::authorize('update', $run);

        return $this->successResponse(new RunResource($this->billing->invoice($run, $this->userId())->load(['statements.store', 'batches'])), $this->message('run_invoiced'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/royalty-runs/{id}/batches', operationId: 'franchiseNetworkRunBatches', summary: 'SEPA SDD and LSV+ collection batches of an invoiced run', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Batches')])]
    public function batches(string $id): JsonResponse
    {
        $run = RoyaltyRun::query()->with('batches')->findOrFail($id);
        Gate::authorize('manage', RoyaltyRun::class);

        return $this->successResponse(CollectionBatchResource::collection($run->batches), $this->message('listed'));
    }
}
