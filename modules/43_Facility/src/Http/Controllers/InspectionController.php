<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\CompleteInspectionRequest;
use Modules\Facility\Http\Requests\ReasonRequest;
use Modules\Facility\Http\Resources\InspectionResource;
use Modules\Facility\Models\FacilityInspection;
use Modules\Facility\Services\InspectionService;

/**
 * Generated inspections: the worklist, starting, completing with the checklist answers and cancelling. Operations are
 * documented in FacilityInspectionOpenApi.
 */
final class InspectionController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly InspectionService $inspections) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('operate', FacilityInspection::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(FacilityInspection::Statuses)],
            'assignee_id' => ['nullable', 'uuid'],
            'building_id' => ['nullable', 'uuid'],
            'template_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'mine' => ['nullable', 'boolean'],
            'open' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        return InspectionResource::collection(
            FacilityInspection::query()
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['assignee_id']), static fn ($query) => $query->where('assignee_id', $filters['assignee_id']))
                ->when(isset($filters['building_id']), static fn ($query) => $query->where('building_id', $filters['building_id']))
                ->when(isset($filters['template_id']), static fn ($query) => $query->where('template_id', $filters['template_id']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('due_date', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('due_date', '<=', $filters['to']))
                ->when($request->boolean('mine'), fn ($query) => $query->where('assignee_id', $this->actorId()))
                ->when($request->boolean('open'), static fn ($query) => $query->whereIn('status', FacilityInspection::OpenStatuses))
                ->when($request->boolean('overdue'), static fn ($query) => $query->whereIn('status', FacilityInspection::OpenStatuses)->whereDate('due_date', '<', now()->toDateString()))
                ->orderBy('due_date')
                ->gridPaginate($this->perPage())
        );
    }

    public function show(string $id): InspectionResource
    {
        $inspection = FacilityInspection::query()->findOrFail($id);
        $this->authorize('view', $inspection);

        return new InspectionResource($inspection);
    }

    public function start(string $id): InspectionResource
    {
        $inspection = FacilityInspection::query()->findOrFail($id);
        $this->authorize('operate', $inspection);

        return new InspectionResource($this->inspections->start($inspection));
    }

    public function complete(CompleteInspectionRequest $request, string $id): InspectionResource
    {
        $inspection = FacilityInspection::query()->findOrFail($id);
        $this->authorize('operate', $inspection);
        $data = $request->validated();

        return new InspectionResource($this->inspections->complete($inspection, $data['answers'], $data['notes'] ?? null, $this->actorId(), $this->tenantId()));
    }

    public function cancel(ReasonRequest $request, string $id): InspectionResource
    {
        $inspection = FacilityInspection::query()->findOrFail($id);
        $this->authorize('operate', $inspection);

        return new InspectionResource($this->inspections->cancel($inspection, (string) $request->validated('reason')));
    }
}
