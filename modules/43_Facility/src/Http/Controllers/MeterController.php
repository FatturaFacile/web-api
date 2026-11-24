<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\MeterReadingRequest;
use Modules\Facility\Http\Requests\MeterRequest;
use Modules\Facility\Http\Resources\MeterReadingResource;
use Modules\Facility\Http\Resources\MeterResource;
use Modules\Facility\Models\FacilityMeter;
use Modules\Facility\Models\FacilityMeterReading;
use Modules\Facility\Services\MeterService;

/**
 * Utility meters, their readings and the monthly consumption summary. Operations are documented in
 * FacilityMeterOpenApi.
 */
final class MeterController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly MeterService $meters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('operate', FacilityMeter::class);
        $filters = $request->validate([
            'building_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(FacilityMeter::Kinds)],
            'active' => ['nullable', 'boolean'],
        ]);

        return MeterResource::collection(
            FacilityMeter::query()
                ->when(isset($filters['building_id']), static fn ($query) => $query->where('building_id', $filters['building_id']))
                ->when(isset($filters['kind']), static fn ($query) => $query->where('kind', $filters['kind']))
                ->when(isset($filters['active']), static fn ($query) => $query->where('is_active', $request->boolean('active')))
                ->orderBy('kind')
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('operate', FacilityMeter::class);
        $filters = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'building_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(FacilityMeter::Kinds)],
        ]);

        return response()->json(['data' => $this->meters->monthlySummary((int) $filters['year'], $filters['building_id'] ?? null, $filters['kind'] ?? null)]);
    }

    public function store(MeterRequest $request): JsonResponse
    {
        $this->authorize('manage', FacilityMeter::class);

        return (new MeterResource($this->meters->createMeter($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): MeterResource
    {
        $meter = FacilityMeter::query()->findOrFail($id);
        $this->authorize('view', $meter);

        return new MeterResource($meter);
    }

    public function update(MeterRequest $request, string $id): MeterResource
    {
        $meter = FacilityMeter::query()->findOrFail($id);
        $this->authorize('manage', $meter);

        return new MeterResource($this->meters->updateMeter($meter, $request->validated()));
    }

    public function destroy(string $id): Response
    {
        $meter = FacilityMeter::query()->findOrFail($id);
        $this->authorize('manage', $meter);
        $meter->delete();

        return response()->noContent();
    }

    public function readings(Request $request, string $id): AnonymousResourceCollection
    {
        $meter = FacilityMeter::query()->findOrFail($id);
        $this->authorize('view', $meter);
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return MeterReadingResource::collection(
            FacilityMeterReading::query()
                ->where('meter_id', $meter->id)
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('read_on', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('read_on', '<=', $filters['to']))
                ->orderByDesc('read_on')
                ->gridPaginate($this->perPage())
        );
    }

    public function recordReading(MeterReadingRequest $request, string $id): JsonResponse
    {
        $meter = FacilityMeter::query()->findOrFail($id);
        $this->authorize('operate', $meter);

        return (new MeterReadingResource($this->meters->recordReading($meter, $request->validated(), $this->actorId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function deleteReading(string $id, string $readingId): Response
    {
        $meter = FacilityMeter::query()->findOrFail($id);
        $this->authorize('manage', $meter);
        $reading = FacilityMeterReading::query()->where('meter_id', $meter->id)->findOrFail($readingId);
        $this->meters->deleteLatestReading($meter, $reading);

        return response()->noContent();
    }
}
