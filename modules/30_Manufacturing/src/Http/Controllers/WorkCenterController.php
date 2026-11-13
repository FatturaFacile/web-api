<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\DateRangeRequest;
use Modules\Manufacturing\Http\Requests\SetWorkCenterCalendarRequest;
use Modules\Manufacturing\Http\Requests\StoreCalendarExceptionRequest;
use Modules\Manufacturing\Http\Requests\StoreWorkCenterRequest;
use Modules\Manufacturing\Http\Resources\MfgWorkCenterResource;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Services\ManufacturingMasterDataService;
use Modules\Manufacturing\Services\WorkCenterService;
use OpenApi\Attributes as OA;

/**
 * Work centres: master data with labour and overhead rates, weekly calendar, dated exceptions, capacity and load.
 */
final class WorkCenterController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly ManufacturingMasterDataService $masterData,
        private readonly WorkCenterService $workCenters,
    ) {}

    /**
     * Paginated work centres.
     */
    #[OA\Get(path: '/api/v1/manufacturing/work-centers', operationId: 'listMfgWorkCenters', description: 'Paginated work centres of the active company.', summary: 'List work centres', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Work centres', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgWorkCenter'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgWorkCenter::query()->with('shifts')->where('tenant_id', $this->tenantId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return MfgWorkCenterResource::collection($query->orderBy('work_center_code')->gridPaginate($this->perPage($request)));
    }

    /**
     * Creates a work centre.
     */
    #[OA\Post(path: '/api/v1/manufacturing/work-centers', operationId: 'storeMfgWorkCenter', description: 'Creates a work centre with its daily capacity, efficiency, availability and labour and overhead rates per hour.', summary: 'Create work centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStoreWorkCenterRequest')),
        responses: [new OA\Response(response: 201, description: 'Work centre created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgWorkCenter')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(StoreWorkCenterRequest $request): JsonResponse
    {
        return (new MfgWorkCenterResource($this->masterData->createWorkCenter($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * One work centre with its calendar.
     */
    #[OA\Get(path: '/api/v1/manufacturing/work-centers/{id}', operationId: 'showMfgWorkCenter', description: 'Returns a work centre of the active company with its weekly calendar.', summary: 'Get work centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Work centre', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgWorkCenter')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgWorkCenterResource
    {
        return new MfgWorkCenterResource($this->owned(MfgWorkCenter::class, $id)->load('shifts'));
    }

    /**
     * Updates a work centre.
     */
    #[OA\Put(path: '/api/v1/manufacturing/work-centers/{id}', operationId: 'updateMfgWorkCenter', description: 'Updates a work centre; new rates apply to orders released from now on (released orders keep the rates they froze).', summary: 'Update work centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStoreWorkCenterRequest')),
        responses: [new OA\Response(response: 200, description: 'Work centre updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgWorkCenter')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function update(StoreWorkCenterRequest $request, string $id): MfgWorkCenterResource
    {
        return new MfgWorkCenterResource($this->masterData->updateWorkCenter($this->owned(MfgWorkCenter::class, $id), $request->validated())->load('shifts'));
    }

    /**
     * Replaces the weekly calendar.
     */
    #[OA\Put(path: '/api/v1/manufacturing/work-centers/{id}/calendar', operationId: 'setMfgWorkCenterCalendar', description: 'Replaces the weekly calendar: gross hours per ISO weekday; weekdays left out have no capacity.', summary: 'Set work centre calendar', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgSetCalendarRequest')),
        responses: [new OA\Response(response: 200, description: 'Calendar set', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgWorkCenter')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function setCalendar(SetWorkCenterCalendarRequest $request, string $id): MfgWorkCenterResource
    {
        /** @var list<array{weekday: int, available_hours: string|int|float}> $days */
        $days = array_values($request->validated('days'));

        return new MfgWorkCenterResource($this->workCenters->setCalendar($this->owned(MfgWorkCenter::class, $id), $days));
    }

    /**
     * Sets a dated capacity exception.
     */
    #[OA\Post(path: '/api/v1/manufacturing/work-centers/{id}/calendar-exceptions', operationId: 'storeMfgWorkCenterException', description: 'Sets the gross hours of one day (0 for a closure), replacing the weekly calendar for that day.', summary: 'Set calendar exception', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgCalendarExceptionRequest')),
        responses: [new OA\Response(response: 201, description: 'Exception set', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgCapacityDay')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function storeException(StoreCalendarExceptionRequest $request, string $id): JsonResponse
    {
        $workCenter = $this->owned(MfgWorkCenter::class, $id);
        $data = $request->validated();
        $this->workCenters->setException($workCenter, (string) $data['calendar_date'], (string) $data['available_hours'], $data['reason'] ?? null);
        $day = $this->workCenters->capacity($workCenter, (string) $data['calendar_date'], (string) $data['calendar_date'])[0];

        return response()->json(['data' => $day], 201);
    }

    /**
     * Capacity and load per day.
     */
    #[OA\Get(path: '/api/v1/manufacturing/work-centers/{id}/capacity', operationId: 'capacityMfgWorkCenter', description: 'Gross and effective hours, load of open order operations and utilization per day of the window.', summary: 'Work centre capacity', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Work Centres'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId'), new OA\Parameter(ref: '#/components/parameters/MfgFrom'), new OA\Parameter(ref: '#/components/parameters/MfgTo')],
        responses: [new OA\Response(response: 200, description: 'Capacity per day', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgCapacityDay'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function capacity(DateRangeRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->workCenters->capacity($this->owned(MfgWorkCenter::class, $id), (string) $data['from'], (string) $data['to'])]);
    }
}
