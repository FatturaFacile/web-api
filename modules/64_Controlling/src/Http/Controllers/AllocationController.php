<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\AllocationPeriodRequest;
use Modules\Controlling\Http\Requests\AllocationRuleRequest;
use Modules\Controlling\Http\Resources\AllocationRuleResource;
use Modules\Controlling\Http\Resources\AllocationRunResource;
use Modules\Controlling\Models\CostAllocationRule;
use Modules\Controlling\Models\CostAllocationRun;
use Modules\Controlling\Services\CostAllocationEngineService;

/**
 * Overhead allocation: driver rules, simulation preview, posting and reversal of a period run.
 */
final class AllocationController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly CostAllocationEngineService $engine) {}

    public function rules(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CostAllocationRule::class);

        return AllocationRuleResource::collection(CostAllocationRule::query()->where('tenant_id', $this->tenantId())->orderBy('step_order')->orderBy('name')->paginate($this->perPage()));
    }

    public function storeRule(AllocationRuleRequest $request): JsonResponse
    {
        $this->authorize('create', CostAllocationRule::class);
        $rule = CostAllocationRule::create(['tenant_id' => $this->tenantId(), 'step_order' => 1, 'is_active' => true, ...$request->validated()]);

        return (new AllocationRuleResource($rule))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateRule(AllocationRuleRequest $request, string $id): AllocationRuleResource
    {
        $rule = CostAllocationRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $rule);
        $rule->update($request->validated());

        return new AllocationRuleResource($rule);
    }

    public function destroyRule(string $id): Response
    {
        $rule = CostAllocationRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $rule);
        $rule->delete();

        return response()->noContent();
    }

    public function runs(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CostAllocationRun::class);

        return AllocationRunResource::collection(CostAllocationRun::query()->where('tenant_id', $this->tenantId())->orderByDesc('fiscal_year')->orderByDesc('fiscal_month')->orderByDesc('created_at')->paginate($this->perPage()));
    }

    public function preview(AllocationPeriodRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CostAllocationRule::class);
        $data = $request->validated();

        return response()->json(['data' => $this->engine->preview($this->tenantId(), (int) $data['fiscal_year'], (int) $data['fiscal_month'])]);
    }

    public function execute(AllocationPeriodRequest $request): JsonResponse
    {
        $this->authorize('runAllocations', CostAllocationRun::class);
        $data = $request->validated();
        $run = $this->domain(fn (): CostAllocationRun => $this->engine->execute($this->tenantId(), (int) $data['fiscal_year'], (int) $data['fiscal_month'], $this->userId()));

        return (new AllocationRunResource($run))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function reverse(string $runId): AllocationRunResource
    {
        $this->authorize('runAllocations', CostAllocationRun::class);

        return new AllocationRunResource($this->domain(fn (): CostAllocationRun => $this->engine->reverse($this->tenantId(), $runId)));
    }
}
