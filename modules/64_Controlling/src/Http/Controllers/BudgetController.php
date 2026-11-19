<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Controlling\Http\Controllers\Concerns\InteractsWithControlling;
use Modules\Controlling\Http\Requests\BudgetCloneRequest;
use Modules\Controlling\Http\Requests\BudgetPlanRequest;
use Modules\Controlling\Http\Requests\BudgetSeedRequest;
use Modules\Controlling\Http\Requests\BulkLinesRequest;
use Modules\Controlling\Http\Requests\RollingForecastRequest;
use Modules\Controlling\Http\Resources\BudgetPlanResource;
use Modules\Controlling\Models\BudgetPlan;
use Modules\Controlling\Services\BudgetVarianceService;

/**
 * Budget plans: create, clone, seed from prior-year actuals, workflow (submit, approve, lock), grid edits and rolling forecast.
 */
final class BudgetController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly BudgetVarianceService $budgets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetPlan::class);
        $filters = $request->validate(['fiscal_year' => ['nullable', 'integer'], 'plan_type' => ['nullable', Rule::in(BudgetPlan::Types)], 'status' => ['nullable', Rule::in(BudgetPlan::Statuses)]]);

        return BudgetPlanResource::collection(
            BudgetPlan::query()->withCount('lines')->where('tenant_id', $this->tenantId())
                ->when(isset($filters['fiscal_year']), static fn ($q) => $q->where('fiscal_year', $filters['fiscal_year']))
                ->when(isset($filters['plan_type']), static fn ($q) => $q->where('plan_type', $filters['plan_type']))
                ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
                ->orderByDesc('fiscal_year')->orderByDesc('version_number')->paginate($this->perPage()),
        );
    }

    public function store(BudgetPlanRequest $request): JsonResponse
    {
        $this->authorize('create', BudgetPlan::class);
        $plan = $this->budgets->createPlan($this->tenantId(), $request->validated());

        return (new BudgetPlanResource($plan))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): BudgetPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('view', $plan);

        return new BudgetPlanResource($plan->load('lines'));
    }

    public function update(BudgetPlanRequest $request, string $id): BudgetPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('update', $plan);
        $this->domain(function () use ($plan, $request): void {
            if ($plan->status !== 'draft') {
                throw new \RuntimeException('Only a draft plan can be edited.');
            }
            $plan->update($request->validated());
        });

        return new BudgetPlanResource($plan);
    }

    public function destroy(string $id): Response
    {
        $plan = $this->find($id);
        $this->authorize('delete', $plan);
        $this->domain(function () use ($plan): void {
            if (in_array($plan->status, ['approved', 'locked'], true)) {
                throw new \RuntimeException('An approved or locked plan cannot be deleted.');
            }
            $plan->delete();
        });

        return response()->noContent();
    }

    public function clone(BudgetCloneRequest $request, string $id): JsonResponse
    {
        $source = $this->find($id);
        $this->authorize('create', BudgetPlan::class);
        $data = $request->validated();
        $plan = $this->budgets->clonePlan($source, $data['code'], $data['name'], (string) ($data['uplift_percent'] ?? 0), $data['plan_type'] ?? null);

        return (new BudgetPlanResource($plan->loadCount('lines')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function seed(BudgetSeedRequest $request): JsonResponse
    {
        $this->authorize('create', BudgetPlan::class);
        $data = $request->validated();
        $plan = $this->budgets->seedFromPriorYear($this->tenantId(), (int) $data['fiscal_year'], $data['code'], $data['name'], (string) ($data['uplift_percent'] ?? 0));

        return (new BudgetPlanResource($plan->loadCount('lines')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function submit(string $id): BudgetPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('update', $plan);

        return new BudgetPlanResource($this->domain(fn (): BudgetPlan => $this->budgets->submit($plan)));
    }

    public function approve(string $id): BudgetPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('approve', $plan);

        return new BudgetPlanResource($this->domain(fn (): BudgetPlan => $this->budgets->approve($plan, $this->userId())));
    }

    public function lock(string $id): BudgetPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('approve', $plan);

        return new BudgetPlanResource($this->domain(fn (): BudgetPlan => $this->budgets->lock($plan)));
    }

    public function bulkLines(BulkLinesRequest $request, string $id): BudgetPlanResource
    {
        $plan = $this->find($id);
        $this->authorize('update', $plan);
        $this->domain(fn (): array => $this->budgets->bulkUpsert($plan, $request->validated()['lines']));

        return new BudgetPlanResource($plan->load('lines'));
    }

    public function rollingForecast(RollingForecastRequest $request, string $id): JsonResponse
    {
        $budget = $this->find($id);
        $this->authorize('create', BudgetPlan::class);
        $data = $request->validated();
        $plan = $this->domain(fn (): BudgetPlan => $this->budgets->generateRollingForecast($budget, (int) $data['cutoff_month'], $data['method'] ?? 'run_rate', $data['overrides'] ?? [], $data['code'] ?? null));

        return (new BudgetPlanResource($plan->load('lines')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(string $id): BudgetPlan
    {
        return BudgetPlan::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
