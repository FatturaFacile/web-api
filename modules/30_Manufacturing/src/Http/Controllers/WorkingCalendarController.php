<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\ConfigureWorkingCalendarRequest;
use Modules\Manufacturing\Http\Requests\DateRangeRequest;
use Modules\Manufacturing\Http\Resources\MfgWorkingCalendarResource;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkingCalendar;
use Modules\Manufacturing\Services\ProductionEngineering\ProductionEngineeringService;
use Modules\Manufacturing\Services\ProductionEngineering\WorkingCalendarResolver;
use OpenApi\Attributes as OA;

/**
 * Working calendars: weekly shifts, planned shutdowns, and the Swiss cantonal or Italian public holidays that close the
 * plant.
 */
final class WorkingCalendarController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly ProductionEngineeringService $engineering,
        private readonly WorkingCalendarResolver $resolver,
    ) {}

    /**
     * Paginated working calendars.
     */
    #[OA\Get(path: '/api/v1/manufacturing/working-calendars', operationId: 'listMfgWorkingCalendars', summary: 'List working calendars', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 200, description: 'Working calendars')])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgWorkingCalendar::query()->with(['shifts', 'closures'])->where('tenant_id', $this->tenantId());

        return MfgWorkingCalendarResource::collection($query->orderBy('code')->gridPaginate($this->perPage($request)));
    }

    /**
     * Creates a working calendar.
     */
    #[OA\Post(path: '/api/v1/manufacturing/working-calendars', operationId: 'storeMfgWorkingCalendar', summary: 'Create working calendar', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], responses: [new OA\Response(response: 201, description: 'Working calendar created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(ConfigureWorkingCalendarRequest $request): JsonResponse
    {
        return (new MfgWorkingCalendarResource($this->engineering->configureCalendar($this->tenantId(), null, $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * One working calendar.
     */
    #[OA\Get(path: '/api/v1/manufacturing/working-calendars/{id}', operationId: 'showMfgWorkingCalendar', summary: 'Get working calendar', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 200, description: 'Working calendar'), new OA\Response(response: 404, description: 'Not found')])]
    public function show(string $id): MfgWorkingCalendarResource
    {
        return new MfgWorkingCalendarResource($this->owned(MfgWorkingCalendar::class, $id)->load(['shifts', 'closures']));
    }

    /**
     * Updates a working calendar; shifts and shutdowns, when sent, replace the existing ones.
     */
    #[OA\Put(path: '/api/v1/manufacturing/working-calendars/{id}', operationId: 'updateMfgWorkingCalendar', summary: 'Update working calendar', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 200, description: 'Working calendar updated'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(ConfigureWorkingCalendarRequest $request, string $id): MfgWorkingCalendarResource
    {
        $calendar = $this->owned(MfgWorkingCalendar::class, $id);

        return new MfgWorkingCalendarResource($this->engineering->configureCalendar($this->tenantId(), $calendar, $request->validated()));
    }

    /**
     * Deletes a working calendar that no work centre uses.
     */
    #[OA\Delete(path: '/api/v1/manufacturing/working-calendars/{id}', operationId: 'deleteMfgWorkingCalendar', summary: 'Delete working calendar', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 409, description: 'In use by a work centre')])]
    public function destroy(string $id): JsonResponse
    {
        $calendar = $this->owned(MfgWorkingCalendar::class, $id);
        if (MfgWorkCenter::query()->where('tenant_id', $this->tenantId())->where('working_calendar_id', $calendar->id)->exists()) {
            return response()->json(['message' => 'The calendar is used by a work centre.', 'errors' => ['working_calendar_id' => ['The calendar is used by a work centre.']]], 409);
        }
        $calendar->delete();

        return response()->json(null, 204);
    }

    /**
     * The days of a window with their type (shift, holiday, weekend, shutdown), holiday name and shifts.
     */
    #[OA\Get(path: '/api/v1/manufacturing/working-calendars/{id}/preview-days', operationId: 'previewMfgWorkingCalendarDays', summary: 'Preview calendar days', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'], parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId'), new OA\Parameter(ref: '#/components/parameters/MfgFrom'), new OA\Parameter(ref: '#/components/parameters/MfgTo')], responses: [new OA\Response(response: 200, description: 'Resolved days'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'Validation error')])]
    public function previewDays(DateRangeRequest $request, string $id): JsonResponse
    {
        $calendar = $this->owned(MfgWorkingCalendar::class, $id);
        $data = $request->validated();
        $from = CarbonImmutable::parse((string) $data['from']);
        $to = CarbonImmutable::parse((string) $data['to']);
        if ($from->diffInDays($to, true) > 365) {
            throw new ManufacturingOperationException('The window is at most 366 days.', 'to');
        }

        $days = array_map(static fn (array $day): array => [
            'date' => $day['date'],
            'day_type' => $day['day_type']->value,
            'label' => $day['label'],
            'available_minutes' => $day['available_minutes'],
            'shifts' => array_map(static fn (array $shift): array => [
                'shift_code' => $shift['shift_code'],
                'start_at' => $shift['start_at']->toIso8601String(),
                'end_at' => $shift['end_at']->toIso8601String(),
                'net_minutes' => $shift['net_minutes'],
                'labor_multiplier' => $shift['labor_multiplier'],
            ], $day['shifts']),
        ], $this->resolver->preview($calendar, $from, $to));

        return response()->json(['data' => $days]);
    }
}
