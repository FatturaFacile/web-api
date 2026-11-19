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
use Modules\Controlling\Http\Requests\JobRequest;
use Modules\Controlling\Http\Resources\JobResource;
use Modules\Controlling\Models\AnalyticalJob;
use Modules\Controlling\Services\CostCenterReportService;

/**
 * Jobs (commesse): CRUD and the profit and loss of a job against its budget.
 */
final class JobController extends Controller
{
    use InteractsWithControlling;

    public function __construct(private readonly CostCenterReportService $reports) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AnalyticalJob::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(AnalyticalJob::Statuses)], 'cost_center_id' => ['nullable', 'uuid']]);

        return JobResource::collection(
            AnalyticalJob::query()->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
                ->when(isset($filters['cost_center_id']), static fn ($q) => $q->where('cost_center_id', $filters['cost_center_id']))
                ->orderBy('code')->paginate($this->perPage()),
        );
    }

    public function store(JobRequest $request): JsonResponse
    {
        $this->authorize('create', AnalyticalJob::class);
        $job = AnalyticalJob::create(['tenant_id' => $this->tenantId(), ...$request->validated()]);

        return (new JobResource($job))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): JobResource
    {
        $job = AnalyticalJob::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $job);

        return new JobResource($job);
    }

    public function update(JobRequest $request, string $id): JobResource
    {
        $job = AnalyticalJob::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $job);
        $job->update($request->validated());

        return new JobResource($job);
    }

    public function destroy(string $id): Response
    {
        $job = AnalyticalJob::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $job);
        $job->delete();

        return response()->noContent();
    }

    public function pnl(string $id): JsonResponse
    {
        $job = AnalyticalJob::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $job);

        return response()->json(['data' => $this->reports->jobPnl($job)]);
    }
}
