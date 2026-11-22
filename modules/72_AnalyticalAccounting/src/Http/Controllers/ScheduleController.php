<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\ScheduleRequest;
use Modules\AnalyticalAccounting\Http\Resources\ScheduleResource;
use Modules\AnalyticalAccounting\Models\ApportionmentSchedule;
use Modules\AnalyticalAccounting\Models\ApportionmentScheduleStep;

/**
 * Step-down schedules: the ordered cascade of sending centers with their drivers.
 */
final class ScheduleController extends Controller
{
    use InteractsWithAnalytical;

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApportionmentSchedule::class);

        return ScheduleResource::collection(ApportionmentSchedule::query()->with('steps')->where('tenant_id', $this->tenantId())->orderBy('name')->paginate($this->perPage()));
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', ApportionmentSchedule::class);
        $schedule = $this->persist(new ApportionmentSchedule, $request->validated());

        return (new ScheduleResource($schedule))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): ScheduleResource
    {
        $schedule = $this->find($id);
        $this->authorize('view', $schedule);

        return new ScheduleResource($schedule->load('steps'));
    }

    public function update(ScheduleRequest $request, string $id): ScheduleResource
    {
        $schedule = $this->find($id);
        $this->authorize('update', $schedule);

        return new ScheduleResource($this->persist($schedule, $request->validated()));
    }

    public function destroy(string $id): Response
    {
        $schedule = $this->find($id);
        $this->authorize('delete', $schedule);
        DB::transaction(function () use ($schedule): void {
            $schedule->steps()->get()->each->delete();
            $schedule->delete();
        });

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(ApportionmentSchedule $schedule, array $data): ApportionmentSchedule
    {
        return DB::transaction(function () use ($schedule, $data): ApportionmentSchedule {
            $steps = $data['steps'];
            unset($data['steps']);
            $schedule->fill(['tenant_id' => $this->tenantId(), 'is_active' => true, ...$data])->save();
            $schedule->steps()->get()->each->forceDelete();
            foreach ($steps as $step) {
                ApportionmentScheduleStep::create($step + ['tenant_id' => $this->tenantId(), 'apportionment_schedule_id' => $schedule->id, 'created_by' => $this->userId()]);
            }

            return $schedule->load('steps');
        });
    }

    private function find(string $id): ApportionmentSchedule
    {
        return ApportionmentSchedule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
