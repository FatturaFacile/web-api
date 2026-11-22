<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\FieldService\Commands\AdvanceFieldWorkOrderStatusHandler;
use Modules\ServiceManagement\FieldService\Commands\ConvertServiceReportToInvoiceCommand;
use Modules\ServiceManagement\FieldService\Commands\ConvertServiceReportToInvoiceHandler;
use Modules\ServiceManagement\FieldService\Commands\DispatchTechnicianWorkOrderCommand;
use Modules\ServiceManagement\FieldService\Commands\DispatchTechnicianWorkOrderHandler;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersCommand;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersHandler;
use Modules\ServiceManagement\FieldService\Commands\ProcessMobileSyncBatchHandler;
use Modules\ServiceManagement\FieldService\Commands\RecordVanStockPartConsumptionCommand;
use Modules\ServiceManagement\FieldService\Commands\RecordVanStockPartConsumptionHandler;
use Modules\ServiceManagement\FieldService\Commands\SignAndCloseServiceReportHandler;
use Modules\ServiceManagement\FieldService\Commands\SignServiceReportRules;
use Modules\ServiceManagement\FieldService\Commands\UploadWorkOrderPhotoHandler;
use Modules\ServiceManagement\FieldService\Queries\GetCustomerEquipmentServiceHistoryQuery;
use Modules\ServiceManagement\FieldService\Queries\GetDispatcherGanttAndMapBoardQuery;
use Modules\ServiceManagement\FieldService\Queries\GetTechnicianOfflineWorkQueueQuery;
use Modules\ServiceManagement\Http\Requests\DispatchTechnicianRequest;
use Modules\ServiceManagement\Http\Requests\RecordVanPartRequest;
use Modules\ServiceManagement\Http\Requests\SaveMaintenanceContractRequest;
use Modules\ServiceManagement\Http\Requests\SignServiceReportRequest;
use Modules\ServiceManagement\Http\Requests\StoreFieldWorkOrderRequest;
use Modules\ServiceManagement\Http\Requests\StoreWorkOrderPhotoRequest;
use Modules\ServiceManagement\Http\Resources\DigitalServiceReportResource;
use Modules\ServiceManagement\Http\Resources\FieldWorkOrderResource;
use Modules\ServiceManagement\Http\Resources\MaintenanceContractResource;
use Modules\ServiceManagement\Http\Resources\WorkOrderPhotoResource;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderPhoto;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Field Service Management (GH-196/197/202): dispatcher board, maintenance contracts and preventive engine, van stock
 * consumption, digital service report with signature, invoicing, and the mobile offline bundle and sync.
 * Every write that a mobile client may replay takes a `client_uuid` and is idempotent.
 */
final class FieldServiceManagementController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenant()->id;
    }

    #[OA\Get(
        path: '/api/v1/fsm/board', operationId: 'fsmDispatcherBoard', summary: 'Dispatcher Gantt and map board',
        description: 'Unassigned work orders, technician lanes with scheduled blocks, skills, van readiness and site coordinates.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Dispatch'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [new OA\Response(response: 200, description: 'Board')]
    )]
    public function board(Request $request, GetDispatcherGanttAndMapBoardQuery $query): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after:from']]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : CarbonImmutable::now()->startOfWeek();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $from->addDays(7);

        return response()->json(['data' => $query($this->tenantId(), $from, $to)]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/work-orders', operationId: 'fsmCreateWorkOrder', summary: 'Create a field work order (idempotent on client_uuid)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FsmFieldWorkOrder')])), new OA\Response(response: 200, description: 'Replay of an existing client_uuid'), new OA\Response(response: 422, description: 'Validation error')]
    )]
    public function storeWorkOrder(StoreFieldWorkOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $this->tenantId();

        if (isset($data['client_uuid'])) {
            $existing = WorkOrder::query()->where('tenant_id', $tenantId)->where('client_uuid', $data['client_uuid'])->first();

            if ($existing !== null) {
                return response()->json(['data' => new FieldWorkOrderResource($existing)]);
            }
        }

        $workOrder = WorkOrder::create([
            'tenant_id' => $tenantId,
            'work_order_number' => 'WO-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => $data['title'],
            'description' => $data['problem_description'] ?? $data['title'],
            'work_type' => $data['work_order_type'],
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'unscheduled',
            'customer_id' => $data['customer_id'],
            'equipment_passport_id' => $data['equipment_passport_id'] ?? null,
            'service_contract_id' => $data['service_contract_id'] ?? null,
            'ticket_id' => $data['source_ticket_id'] ?? null,
            'required_skills' => $data['required_skills'] ?? [],
            'required_parts' => $data['required_parts'] ?? [],
            'client_uuid' => $data['client_uuid'] ?? null,
            'metadata' => ['internal_notes' => $data['internal_notes'] ?? null],
            'location' => ['address' => $data['site_address'] ?? null, 'lat' => $data['site_latitude'] ?? null, 'lng' => $data['site_longitude'] ?? null],
        ]);

        return response()->json(['data' => new FieldWorkOrderResource($workOrder)], 201);
    }

    #[OA\Get(
        path: '/api/v1/fsm/work-orders/{workOrder}', operationId: 'fsmShowWorkOrder', summary: 'Show a field work order with its parts and service report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Work order')]
    )]
    public function showWorkOrder(string $workOrder): JsonResponse
    {
        $record = WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);
        $report = DigitalServiceReport::query()->where('work_order_id', $record->id)->first();
        $parts = PartsConsumption::query()->where('work_order_id', $record->id)->orderBy('created_at')->get();

        return response()->json(['data' => (new FieldWorkOrderResource($record))->resolve() + [
            'parts' => $parts->map(static fn ($p): array => [
                'id' => (string) $p->id,
                'product_id' => (string) $p->product_id,
                'description' => (string) (((array) $p->metadata)['description'] ?? $p->lot_number),
                'quantity' => (string) $p->quantity,
                'unit_price' => (string) $p->unit_price,
                'covered' => (bool) $p->is_covered_by_warranty_or_contract,
                'warehouse_id' => $p->warehouse_id === null ? null : (string) $p->warehouse_id,
                'stock_movement_id' => $p->stock_movement_id === null ? null : (string) $p->stock_movement_id,
            ])->values()->all(),
            'service_report' => $report === null ? null : (new DigitalServiceReportResource($report))->resolve(),
        ]]);
    }

    #[OA\Get(
        path: '/api/v1/fsm/work-orders', operationId: 'fsmListWorkOrders', summary: 'List field work orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'work_order_type', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Work orders')]
    )]
    public function indexWorkOrders(Request $request): JsonResponse
    {
        $query = WorkOrder::query()->where('tenant_id', $this->tenantId())->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('work_order_type')) {
            $query->where('work_type', (string) $request->query('work_order_type'));
        }

        return response()->json(['data' => FieldWorkOrderResource::collection($query->limit(200)->get())->resolve()]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/work-orders/{workOrder}/dispatch', operationId: 'fsmDispatchWorkOrder', summary: 'Dispatch a work order to a technician',
        description: 'Validates schedule overlap, required skills and van stock; all blockers are returned together as a 422.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Dispatch'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Dispatched'), new OA\Response(response: 422, description: 'Blocked (schedule, skills, van_stock)')]
    )]
    public function dispatch(DispatchTechnicianRequest $request, string $workOrder, DispatchTechnicianWorkOrderHandler $handler): JsonResponse
    {
        $data = $request->validated();
        WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);

        $dispatched = $handler(new DispatchTechnicianWorkOrderCommand(
            workOrderId: $workOrder,
            technicianId: $data['technician_id'],
            scheduledStart: CarbonImmutable::parse($data['scheduled_start']),
            scheduledEnd: CarbonImmutable::parse($data['scheduled_end']),
            actorUserId: $request->user()?->id,
        ));

        return response()->json(['data' => new FieldWorkOrderResource($dispatched)]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/work-orders/{workOrder}/status', operationId: 'fsmAdvanceWorkOrderStatus', summary: 'Move a dispatched work order to en_route or on_site (idempotent)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Updated')]
    )]
    public function advanceStatus(Request $request, string $workOrder, AdvanceFieldWorkOrderStatusHandler $handler): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:en_route,on_site'], 'at' => ['nullable', 'date']]);
        WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);

        return response()->json(['data' => new FieldWorkOrderResource($handler($workOrder, $data['status'], isset($data['at']) ? CarbonImmutable::parse($data['at']) : null))]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/work-orders/{workOrder}/parts', operationId: 'fsmRecordVanPart', summary: 'Record a spare part taken from the technician van (idempotent on client_uuid)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 201, description: 'Recorded'), new OA\Response(response: 200, description: 'Replay'), new OA\Response(response: 422, description: 'Rejected')]
    )]
    public function recordPart(RecordVanPartRequest $request, string $workOrder, RecordVanStockPartConsumptionHandler $handler): JsonResponse
    {
        $data = $request->validated();
        WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);

        $consumption = $handler(new RecordVanStockPartConsumptionCommand(
            workOrderId: $workOrder,
            productId: $data['product_id'],
            quantity: (string) $data['quantity'],
            unitPrice: (string) ($data['unit_price'] ?? '0'),
            lotOrSerialId: $data['lot_or_serial_id'] ?? null,
            lotNumber: $data['lot_number'] ?? null,
            clientUuid: $data['client_uuid'] ?? null,
            actorUserId: $request->user()?->id,
        ));

        return response()->json(['data' => [
            'id' => (string) $consumption->id,
            'covered' => (bool) $consumption->is_covered_by_warranty_or_contract,
            'unit_price' => (string) $consumption->unit_price,
            'stock_movement_id' => $consumption->stock_movement_id === null ? null : (string) $consumption->stock_movement_id,
        ]], $consumption->wasRecentlyCreated ? 201 : 200);
    }

    #[OA\Post(
        path: '/api/v1/fsm/work-orders/{workOrder}/photos', operationId: 'fsmUploadWorkOrderPhoto', summary: 'Upload a photo of a work order (idempotent on client_uuid)',
        description: 'Multipart upload from the technician app. A replay of the same client_uuid returns 200 with the existing record; the first call returns 201. The id can be referenced by `photo_ids` when signing the service report.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['photo', 'client_uuid'], properties: [
            new OA\Property(property: 'photo', type: 'string', format: 'binary', description: 'jpeg, png, heic or webp, max 10 MB'),
            new OA\Property(property: 'client_uuid', type: 'string', maxLength: 64),
            new OA\Property(property: 'stage', type: 'string', enum: ['before', 'during', 'after'], nullable: true),
            new OA\Property(property: 'caption', type: 'string', maxLength: 255, nullable: true),
            new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
        ]))),
        responses: [new OA\Response(response: 201, description: 'Uploaded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FsmWorkOrderPhoto')])), new OA\Response(response: 200, description: 'Replay of an existing client_uuid'), new OA\Response(response: 404, description: 'Work order not found'), new OA\Response(response: 422, description: 'Validation error')]
    )]
    public function uploadPhoto(StoreWorkOrderPhotoRequest $request, string $workOrder, UploadWorkOrderPhotoHandler $handler): JsonResponse
    {
        $data = $request->validated();
        $record = WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);

        $file = $request->file('photo');
        abort_unless($file instanceof UploadedFile, 422);

        $photo = $handler(
            $record,
            $file,
            (string) $data['client_uuid'],
            $data['stage'] ?? null,
            $data['caption'] ?? null,
            isset($data['captured_at']) ? CarbonImmutable::parse($data['captured_at']) : null,
            $request->user()?->id === null ? null : (string) $request->user()->id,
        );

        return response()->json(['data' => (new WorkOrderPhotoResource($photo))->resolve()], $photo->wasRecentlyCreated ? 201 : 200);
    }

    #[OA\Get(
        path: '/api/v1/fsm/work-orders/{workOrder}/photos', operationId: 'fsmListWorkOrderPhotos', summary: 'List the photos of a work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Photos', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FsmWorkOrderPhoto'))])), new OA\Response(response: 404, description: 'Work order not found')]
    )]
    public function listPhotos(string $workOrder): JsonResponse
    {
        $record = WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);
        $photos = WorkOrderPhoto::query()->where('tenant_id', $record->tenant_id)->where('work_order_id', $record->id)->orderBy('created_at')->get();

        return response()->json(['data' => WorkOrderPhotoResource::collection($photos)->resolve()]);
    }

    #[OA\Get(
        path: '/api/v1/fsm/work-orders/{workOrder}/photos/{photo}', operationId: 'fsmDownloadWorkOrderPhoto', summary: 'Download a work order photo',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Work Orders'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'photo', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Image binary'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function showPhoto(string $workOrder, string $photo): StreamedResponse
    {
        $record = WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);
        $file = WorkOrderPhoto::query()->where('tenant_id', $record->tenant_id)->where('work_order_id', $record->id)->findOrFail($photo);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, basename($file->path));
    }

    #[OA\Post(
        path: '/api/v1/fsm/work-orders/{workOrder}/service-report', operationId: 'fsmSignServiceReport', summary: 'Sign and close the service report (idempotent on client_uuid)',
        description: 'Saves checklist, labor/travel metrics, photos, GPS and the customer signature sealed with SHA-256, completes the work order, generates the localized PDF and queues it to the customer. A replay returns 200 with the original report.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Service Reports'],
        parameters: [new OA\Parameter(name: 'workOrder', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 201, description: 'Signed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FsmServiceReport')])), new OA\Response(response: 200, description: 'Replay'), new OA\Response(response: 422, description: 'Rejected')]
    )]
    public function signReport(SignServiceReportRequest $request, string $workOrder, SignAndCloseServiceReportHandler $handler): JsonResponse
    {
        WorkOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($workOrder);
        $report = $handler(SignServiceReportRules::command($workOrder, $request->validated(), $request->user()?->id));

        return response()->json(['data' => new DigitalServiceReportResource($report)], $report->wasRecentlyCreated ? 201 : 200);
    }

    #[OA\Get(
        path: '/api/v1/fsm/service-reports/{report}/pdf', operationId: 'fsmServiceReportPdf', summary: 'Download the signed service report PDF',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Service Reports'],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf'))]
    )]
    public function pdf(string $report): StreamedResponse
    {
        $record = DigitalServiceReport::query()->where('tenant_id', $this->tenantId())->findOrFail($report);
        abort_if($record->pdf_path === null || ! Storage::disk('local')->exists($record->pdf_path), 404);

        return Storage::disk('local')->download($record->pdf_path, "{$record->report_number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    #[OA\Post(
        path: '/api/v1/fsm/service-reports/{report}/invoice', operationId: 'fsmInvoiceServiceReport', summary: 'Create the draft invoice of the billable part of a service report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Service Reports'],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 201, description: 'Invoice drafted'), new OA\Response(response: 422, description: 'Nothing billable / already invoiced')]
    )]
    public function invoice(Request $request, string $report, ConvertServiceReportToInvoiceHandler $handler): JsonResponse
    {
        $data = $request->validate(['tax_rate' => ['required', 'numeric', 'between:0,100'], 'due_date' => ['nullable', 'date'], 'revenue_account_id' => ['nullable', 'uuid']]);
        DigitalServiceReport::query()->where('tenant_id', $this->tenantId())->findOrFail($report);

        $invoice = $handler(new ConvertServiceReportToInvoiceCommand($report, (string) $data['tax_rate'], $data['due_date'] ?? null, $data['revenue_account_id'] ?? null, $request->user()?->id));

        return response()->json(['data' => ['invoice_id' => (string) $invoice->id]], 201);
    }

    #[OA\Get(
        path: '/api/v1/fsm/technicians/{technician}/offline-bundle', operationId: 'fsmTechnicianOfflineBundle', summary: 'Delta-sync bundle for the mobile offline client',
        description: 'Open work orders, customers, equipment with history, checklist templates and the full van stock. Pass the previous `server_time` as `since` for a delta.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Mobile'],
        parameters: [new OA\Parameter(name: 'technician', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))],
        responses: [new OA\Response(response: 200, description: 'Bundle')]
    )]
    public function offlineBundle(Request $request, string $technician, GetTechnicianOfflineWorkQueueQuery $query): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'date']]);

        return response()->json(['data' => $query($this->tenantId(), $technician, isset($data['since']) ? CarbonImmutable::parse($data['since']) : null)]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/mobile-sync', operationId: 'fsmMobileSync', summary: 'Apply a batch of queued offline operations',
        description: 'Operations are applied in order; each is reported applied, duplicate (same client_uuid already applied) or rejected with field errors.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'operations', type: 'array', maxItems: 200, items: new OA\Items(properties: [
            new OA\Property(property: 'client_uuid', type: 'string'),
            new OA\Property(property: 'type', type: 'string', enum: ['status_transition', 'part_consumption', 'service_report']),
            new OA\Property(property: 'work_order_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'payload', type: 'object'),
        ], type: 'object'))])),
        responses: [new OA\Response(response: 200, description: 'Per-operation results')]
    )]
    public function mobileSync(Request $request, ProcessMobileSyncBatchHandler $handler): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:200'],
            'operations.*.client_uuid' => ['required', 'string', 'max:64'],
            'operations.*.type' => ['required', 'in:status_transition,part_consumption,service_report'],
            'operations.*.work_order_id' => ['required', 'uuid'],
            'operations.*.payload' => ['required', 'array'],
        ]);

        $tenantId = $this->tenantId();
        $known = WorkOrder::query()->where('tenant_id', $tenantId)->whereIn('id', collect($data['operations'])->pluck('work_order_id'))->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $operations = array_map(static fn (array $op): array => in_array($op['work_order_id'], $known, true) ? $op : ['work_order_id' => '00000000-0000-0000-0000-000000000000'] + $op, $data['operations']);

        return response()->json(['data' => ['results' => $handler($operations, $request->user()?->id), 'server_time' => CarbonImmutable::now()->toIso8601String()]]);
    }

    #[OA\Get(
        path: '/api/v1/fsm/equipment/{equipment}/service-history', operationId: 'fsmEquipmentServiceHistory', summary: 'Service reports, replaced parts and meter progression of a customer asset',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Equipment'],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'History')]
    )]
    public function equipmentHistory(string $equipment, GetCustomerEquipmentServiceHistoryQuery $query): JsonResponse
    {
        return response()->json(['data' => $query($this->tenantId(), $equipment)]);
    }

    #[OA\Patch(
        path: '/api/v1/fsm/equipment/{equipment}/meters', operationId: 'fsmUpdateEquipmentMeters', summary: 'Record an operating-hours / cycle counter reading (never lower than the current one)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Equipment'],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Updated'), new OA\Response(response: 422, description: 'Reading lower than the recorded one')]
    )]
    public function updateMeters(Request $request, string $equipment): JsonResponse
    {
        $data = $request->validate(['current_operating_hours' => ['nullable', 'numeric', 'min:0'], 'current_cycle_count' => ['nullable', 'integer', 'min:0']]);
        $passport = SerializedEquipmentPassport::query()->where('tenant_id', $this->tenantId())->findOrFail($equipment);

        if ((isset($data['current_operating_hours']) && (float) $data['current_operating_hours'] < (float) $passport->current_operating_hours)
            || (isset($data['current_cycle_count']) && (int) $data['current_cycle_count'] < (int) $passport->current_cycle_count)) {
            throw ValidationException::withMessages(['meter_reading' => __('field_service.errors.meter_regression')]);
        }

        $passport->update(array_filter($data, static fn ($v): bool => $v !== null));

        return response()->json(['data' => ['id' => (string) $passport->id, 'current_operating_hours' => (string) $passport->current_operating_hours, 'current_cycle_count' => (int) $passport->current_cycle_count]]);
    }

    #[OA\Get(
        path: '/api/v1/fsm/equipment', operationId: 'fsmListEquipment', summary: 'List customer equipment with meters and the maintenance contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Equipment'],
        responses: [new OA\Response(response: 200, description: 'Equipment')]
    )]
    public function equipment(): JsonResponse
    {
        $rows = SerializedEquipmentPassport::query()->where('tenant_id', $this->tenantId())->orderBy('serial_number')->limit(500)->get();

        return response()->json(['data' => $rows->map(static fn (SerializedEquipmentPassport $p): array => [
            'id' => (string) $p->id,
            'serial_number' => (string) $p->serial_number,
            'product_id' => $p->product_id === null ? null : (string) $p->product_id,
            'customer_id' => (string) $p->current_owner_customer_id,
            'service_contract_id' => $p->service_contract_id === null ? null : (string) $p->service_contract_id,
            'site_address' => $p->site_address,
            'site_latitude' => $p->site_latitude,
            'site_longitude' => $p->site_longitude,
            'current_operating_hours' => (string) $p->current_operating_hours,
            'current_cycle_count' => (int) $p->current_cycle_count,
            'last_service_date' => $p->last_service_date?->toDateString(),
            'next_scheduled_service_date' => $p->next_scheduled_service_date?->toDateString(),
        ])->values()->all()]);
    }

    #[OA\Get(
        path: '/api/v1/fsm/maintenance-contracts', operationId: 'fsmListMaintenanceContracts', summary: 'List maintenance contracts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Contracts'],
        responses: [new OA\Response(response: 200, description: 'Contracts', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FsmMaintenanceContract'))]))]
    )]
    public function contracts(): JsonResponse
    {
        $contracts = ServiceContract::query()->where('tenant_id', $this->tenantId())->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => MaintenanceContractResource::collection($contracts)->resolve()]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/maintenance-contracts', operationId: 'fsmCreateMaintenanceContract', summary: 'Create a maintenance contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Contracts'],
        responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FsmMaintenanceContract')]))]
    )]
    public function storeContract(SaveMaintenanceContractRequest $request): JsonResponse
    {
        $data = $request->validated();

        $contract = DB::transaction(function () use ($data): ServiceContract {
            $contract = ServiceContract::create($this->contractAttributes($data) + ['tenant_id' => $this->tenantId(), 'status' => $data['status'] ?? 'draft']);
            $this->attachEquipment($contract, $data['equipment_passport_ids'] ?? []);

            return $contract;
        });

        return response()->json(['data' => new MaintenanceContractResource($contract)], 201);
    }

    #[OA\Patch(
        path: '/api/v1/fsm/maintenance-contracts/{contract}', operationId: 'fsmUpdateMaintenanceContract', summary: 'Update a maintenance contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Contracts'],
        parameters: [new OA\Parameter(name: 'contract', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FsmMaintenanceContract')]))]
    )]
    public function updateContract(SaveMaintenanceContractRequest $request, string $contract): JsonResponse
    {
        $data = $request->validated();
        $record = ServiceContract::query()->where('tenant_id', $this->tenantId())->findOrFail($contract);

        DB::transaction(function () use ($record, $data): void {
            $record->update($this->contractAttributes($data) + (isset($data['status']) ? ['status' => $data['status']] : []));

            if (array_key_exists('equipment_passport_ids', $data)) {
                SerializedEquipmentPassport::query()->where('service_contract_id', $record->id)->update(['service_contract_id' => null]);
                $this->attachEquipment($record, $data['equipment_passport_ids'] ?? []);
            }
        });

        return response()->json(['data' => new MaintenanceContractResource($record->refresh())]);
    }

    #[OA\Post(
        path: '/api/v1/fsm/preventive-maintenance/run', operationId: 'fsmRunPreventiveEngine', summary: 'Run the preventive maintenance engine now (idempotent per maintenance cycle)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['FSM Contracts'],
        responses: [new OA\Response(response: 200, description: 'Run result: evaluated assets, created work order ids, skipped existing cycles')]
    )]
    public function runPreventive(Request $request, GeneratePreventiveMaintenanceWorkOrdersHandler $handler): JsonResponse
    {
        return response()->json(['data' => $handler(new GeneratePreventiveMaintenanceWorkOrdersCommand($this->tenantId(), null, $request->user()?->id))]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contractAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'customer_id', 'contract_number', 'title', 'start_date', 'end_date', 'coverage_type', 'preventive_frequency_rule',
            'included_visits_per_year', 'callout_fee_amount', 'hourly_labor_rate', 'recurring_billing_subscription_id', 'currency', 'notes',
        ]));
    }

    /**
     * @param  list<string>  $passportIds
     */
    private function attachEquipment(ServiceContract $contract, array $passportIds): void
    {
        if ($passportIds !== []) {
            SerializedEquipmentPassport::query()->where('tenant_id', $contract->tenant_id)->whereIn('id', $passportIds)->update(['service_contract_id' => $contract->id]);
        }
    }
}
