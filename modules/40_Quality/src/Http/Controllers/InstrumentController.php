<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Requests\CalibrationRequest;
use Modules\Quality\Http\Requests\InstrumentRequest;
use Modules\Quality\Http\Resources\CalibrationResource;
use Modules\Quality\Http\Resources\InstrumentResource;
use Modules\Quality\Models\QmsCalibration;
use Modules\Quality\Models\QmsInstrument;
use Modules\Quality\Services\InstrumentService;
use OpenApi\Attributes as OA;

/**
 * Measuring instruments, their calibration plan (interval and due date), certificates and out-of-tolerance impact.
 */
final class InstrumentController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly InstrumentService $instruments) {}

    #[OA\Get(path: '/api/v1/quality/instruments', operationId: 'listQmsInstruments', description: 'Paginated instruments by next due date. Filters: status, calibration=overdue|due_soon (active instruments past their due date or due within 30 days).', summary: 'List measuring instruments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'quarantined', 'retired'])),
        new OA\Parameter(name: 'calibration', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['overdue', 'due_soon'])),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Instruments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsInstrument'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsInstrument::class);
        $today = now()->toDateString();

        return InstrumentResource::collection(
            QmsInstrument::query()->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->query('calibration') === 'overdue', static fn ($query) => $query->where('status', 'active')->where(static fn ($inner) => $inner->whereNull('next_due_on')->orWhere('next_due_on', '<', $today)))
                ->when($request->query('calibration') === 'due_soon', static fn ($query) => $query->where('status', 'active')->whereBetween('next_due_on', [$today, now()->addDays(QmsInstrument::DueSoonDays)->toDateString()]))
                ->orderBy('next_due_on')->orderBy('code')->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/instruments', operationId: 'storeQmsInstrument', description: 'Registers an instrument. It has no due date until its first calibration is recorded, so it is reported overdue.', summary: 'Register an instrument', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsInstrumentRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Instrument registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsInstrument')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(InstrumentRequest $request): JsonResponse
    {
        $this->authorize('create', QmsInstrument::class);

        return (new InstrumentResource($this->instruments->create($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/instruments/{id}', operationId: 'showQmsInstrument', description: 'An instrument with its calibration status.', summary: 'Show an instrument', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Instrument', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsInstrument')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): InstrumentResource
    {
        $instrument = $this->find($id);
        $this->authorize('view', $instrument);

        return new InstrumentResource($instrument);
    }

    #[OA\Put(path: '/api/v1/quality/instruments/{id}', operationId: 'updateQmsInstrument', description: 'Changes the master data, tolerance or calibration interval of an instrument.', summary: 'Update an instrument', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsInstrumentRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Instrument updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsInstrument')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(InstrumentRequest $request, string $id): InstrumentResource
    {
        $instrument = $this->find($id);
        $this->authorize('update', $instrument);

        return new InstrumentResource($this->instruments->update($instrument, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/quality/instruments/{id}/retire', operationId: 'retireQmsInstrument', description: 'Takes the instrument out of service: no further calibrations, no longer reported as due.', summary: 'Retire an instrument', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Instrument retired', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsInstrument')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function retire(string $id): InstrumentResource
    {
        $instrument = $this->find($id);
        $this->authorize('update', $instrument);

        return new InstrumentResource($this->instruments->retire($instrument));
    }

    #[OA\Get(path: '/api/v1/quality/instruments/{id}/calibrations', operationId: 'listQmsCalibrations', description: 'Calibration history of an instrument, newest first.', summary: 'List calibrations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Calibrations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsCalibration'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function calibrations(string $id): AnonymousResourceCollection
    {
        $instrument = $this->find($id);
        $this->authorize('view', $instrument);

        return CalibrationResource::collection($instrument->calibrations()->get());
    }

    #[OA\Post(path: '/api/v1/quality/instruments/{id}/calibrations', operationId: 'storeQmsCalibration', description: 'Records a calibration with its certificate. The due date moves forward by the calibration interval (or next_due_on). An out_of_tolerance result quarantines the instrument and raises a major non-conformity; a pass whose as-found error exceeds the tolerance is refused.', summary: 'Record a calibration', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsCalibrationRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 201, description: 'Calibration recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCalibration')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function storeCalibration(CalibrationRequest $request, string $id): JsonResponse
    {
        $instrument = $this->find($id);
        $this->authorize('update', $instrument);

        return (new CalibrationResource($this->instruments->recordCalibration($instrument, $request->validated(), $this->userId())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/calibrations/{id}/impact', operationId: 'getQmsCalibrationImpact', description: 'SPC samples measured with the instrument between the previous calibration and this one, grouped by characteristic with their lots: the results to review when the calibration was out of tolerance.', summary: 'Out-of-tolerance impact of a calibration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Impact', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCalibrationImpact')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function impact(string $id): JsonResponse
    {
        $calibration = QmsCalibration::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $calibration);

        return response()->json(['data' => $this->instruments->impact($calibration)]);
    }

    private function find(string $id): QmsInstrument
    {
        return QmsInstrument::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
