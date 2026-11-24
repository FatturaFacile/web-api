<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Workshop\Http\Requests\LabourTimeRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\TimeEntry;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;
use Modules\Workshop\Services\LabourTimeService;
use OpenApi\Attributes as OA;

/**
 * Labour times of the technicians on a folder, their billing as labour lines, and the service history of a vehicle.
 */
final class LabourTimeController extends Controller
{
    public function __construct(private readonly LabourTimeService $time) {}

    /**
     * Return the time entries of a folder with the minutes and hours per technician.
     */
    #[OA\Get(path: '/api/v1/workshop/folders/{id}/time-entries', operationId: 'listWorkshopTimeEntries', description: 'Return the labour time entries of a folder, with the minutes and hours per technician and how much is not billed yet.', summary: 'List labour time', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Entries and totals', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopTimeSummaryResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function index(string $id): JsonResponse
    {
        $summary = $this->time->summary($this->folder($id, 'view'));

        return response()->json(['data' => ['entries' => WorkshopResource::collection($summary['entries'])->resolve(), 'technicians' => $summary['technicians']]]);
    }

    /**
     * Clock a technician on a folder in progress.
     */
    #[OA\Post(path: '/api/v1/workshop/folders/{id}/time-entries/start', operationId: 'startWorkshopTimeEntry', description: 'Clock a technician (the caller when not named) on a folder in progress. A technician works on one folder at a time: stop the running entry first.', summary: 'Start labour time', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopTimeStartRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Time started', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function start(LabourTimeRequest $request, string $id): JsonResponse
    {
        $technician = (string) ($request->validated('technician_user_id') ?? $request->user()?->getKey());
        $entry = $this->time->start($this->folder($id, 'update'), $technician, $request->validated('notes'));

        return (new WorkshopResource($entry))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Enter time worked on a folder without clocking.
     */
    #[OA\Post(path: '/api/v1/workshop/folders/{id}/time-entries', operationId: 'storeWorkshopTimeEntry', description: 'Enter time worked on a folder that is not invoiced or cancelled, for work that was not clocked on the job.', summary: 'Enter labour time', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopTimeEntryRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Time entered', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(LabourTimeRequest $request, string $id): JsonResponse
    {
        $entry = $this->time->enter($this->folder($id, 'update'), $request->validated());

        return (new WorkshopResource($entry))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Clock the technician off.
     */
    #[OA\Post(path: '/api/v1/workshop/time-entries/{id}/stop', operationId: 'stopWorkshopTimeEntry', description: 'Clock the technician off: the entry keeps the whole minutes elapsed, rounded down.', summary: 'Stop labour time', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Time stopped', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function stop(string $id): WorkshopResource
    {
        $entry = TimeEntry::query()->findOrFail($id);
        $this->authorize('update', $entry);

        return new WorkshopResource($this->time->stop($entry));
    }

    /**
     * Bill the unbilled time of a folder as labour lines, one per technician.
     */
    #[OA\Post(path: '/api/v1/workshop/folders/{id}/time-entries/bill', operationId: 'billWorkshopTimeEntries', description: 'Turn the unbilled time of a folder into labour lines, one per technician, at the hourly rate and tax rate given in the request. Running entries must be stopped first; billed time is never billed twice. The lines then follow the quote, delivery note and invoice of the folder.', summary: 'Bill labour time', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopTimeBillRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Labour lines created', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function bill(LabourTimeRequest $request, string $id): JsonResponse
    {
        $lines = $this->time->bill($this->folder($id, 'update'), (string) $request->validated('hourly_rate'), (string) $request->validated('tax_rate'), $request->validated('description'));

        return WorkshopResource::collection($lines)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return the service history of a vehicle: its folders with mileage and net total.
     */
    #[OA\Get(path: '/api/v1/workshop/vehicles/{id}/history', operationId: 'workshopVehicleHistory', description: 'Return the service history of a customer vehicle: its folders, newest first, with status, mileage in and out, complaint and the net total of the lines.', summary: 'Vehicle service history', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'History', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopVehicleHistoryResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function history(string $id): JsonResponse
    {
        $vehicle = Vehicle::query()->findOrFail($id);
        $this->authorize('view', $vehicle);
        $folders = WorkshopFolder::query()->where('vehicle_id', $vehicle->id)->with('lines')->orderByDesc('received_at')->get();

        return response()->json(['data' => $folders->map(static fn (WorkshopFolder $folder): array => [
            ...$folder->only(['id', 'folder_number', 'kind', 'status', 'received_at', 'mileage_in', 'mileage_out', 'complaint', 'currency']),
            'net_total' => Decimal::round2((string) $folder->lines->reduce(static fn (string $sum, WorkshopFolderLine $line): string => Decimal::add($sum, (string) $line->line_total), '0')),
        ])->values()->all()]);
    }

    private function folder(string $id, string $ability): WorkshopFolder
    {
        $folder = WorkshopFolder::query()->findOrFail($id);
        $this->authorize($ability, $folder);

        return $folder;
    }
}
