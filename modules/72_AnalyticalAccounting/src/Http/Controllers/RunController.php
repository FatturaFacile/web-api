<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\RunRequest;
use Modules\AnalyticalAccounting\Http\Resources\RunResource;
use Modules\AnalyticalAccounting\Models\ApportionmentRun;
use Modules\AnalyticalAccounting\Services\ApportionmentService;

/**
 * Step-down cycle console: preview with the full allocation trace, consolidate, reverse and inspect historical runs.
 */
final class RunController extends Controller
{
    use InteractsWithAnalytical;

    public function __construct(private readonly ApportionmentService $apportionment) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApportionmentRun::class);
        $query = ApportionmentRun::query()->where('tenant_id', $this->tenantId())->orderByDesc('period_key')->orderByDesc('created_at');
        foreach (['status', 'period_key', 'apportionment_schedule_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return RunResource::collection($query->paginate($this->perPage()));
    }

    public function show(string $id): RunResource
    {
        $run = $this->find($id);
        $this->authorize('view', $run);

        return new RunResource($run->load(['steps', 'allocations']));
    }

    public function preview(RunRequest $request): JsonResponse
    {
        $this->authorize('runApportionment', ApportionmentRun::class);
        $data = $request->validated();
        $run = $this->domain(fn (): ApportionmentRun => $this->apportionment->preview($this->tenantId(), $data['schedule_id'], $data['period_key'], $this->userId()));

        return (new RunResource($run->load(['steps', 'allocations'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function consolidate(string $id): JsonResponse
    {
        $this->authorize('runApportionment', ApportionmentRun::class);
        $run = $this->domain(fn (): ApportionmentRun => $this->apportionment->consolidate($this->tenantId(), $id, $this->userId()));

        // The snapshot is rebuilt as a new row (wasRecentlyCreated would yield 201): this is a state transition, so force 200.
        return (new RunResource($run->load(['steps', 'allocations'])))->response()->setStatusCode(Response::HTTP_OK);
    }

    public function reverse(RunRequest $request, string $id): RunResource
    {
        $this->authorize('runApportionment', ApportionmentRun::class);
        $run = $this->domain(fn (): ApportionmentRun => $this->apportionment->reverse($this->tenantId(), $id, $request->validated()['reason'] ?? null, $this->userId()));

        return new RunResource($run->load(['steps', 'allocations']));
    }

    private function find(string $id): ApportionmentRun
    {
        return ApportionmentRun::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
