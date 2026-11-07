<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\FolderActionRequest;
use Modules\Workshop\Http\Requests\SaveFolderRequest;
use Modules\Workshop\Http\Requests\StoreFolderLineRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;
use Modules\Workshop\Services\WorkshopService;
use OpenApi\Attributes as OA;

/**
 * Work folders: reception, lines, status, and the quote, delivery note and invoice made from them.
 */
final class FolderController extends Controller
{
    public function __construct(private readonly WorkshopService $workshop) {}

    #[OA\Get(path: '/api/v1/workshop/folders', operationId: 'listWorkshopFolders', description: 'Return the work folders, newest first, optionally by status or vehicle.', summary: 'List work folders', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'vehicle_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Folders', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkshopFolder::class);

        $folders = WorkshopFolder::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('vehicle_id'), fn ($query) => $query->where('vehicle_id', $request->string('vehicle_id')->toString()))
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 25), 100)));

        return WorkshopResource::collection($folders);
    }

    #[OA\Post(path: '/api/v1/workshop/folders', operationId: 'storeWorkshopFolder', description: 'Open a work folder for a vehicle of the company.', summary: 'Open work folder', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Folder opened', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(SaveFolderRequest $request): JsonResponse
    {
        $this->authorize('create', WorkshopFolder::class);

        return (new WorkshopResource($this->workshop->openFolder($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/workshop/folders/{id}', operationId: 'showWorkshopFolder', description: 'Return a work folder with its lines and vehicle.', summary: 'Show work folder', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Folder', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function show(string $id): WorkshopResource
    {
        $folder = $this->find($id, 'view');

        return new WorkshopResource($folder->load(['lines', 'vehicle']));
    }

    #[OA\Put(path: '/api/v1/workshop/folders/{id}', operationId: 'updateWorkshopFolder', description: 'Change the reception data of an open folder.', summary: 'Change work folder', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Folder changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function update(SaveFolderRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->workshop->updateFolder($this->find($id, 'update'), $request->validated()));
    }

    #[OA\Post(path: '/api/v1/workshop/folders/{id}/lines', operationId: 'storeWorkshopFolderLine', description: 'Add labour, a part, paint material or sublet work to a folder.', summary: 'Add folder line', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderLineRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Line added', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function storeLine(StoreFolderLineRequest $request, string $id): JsonResponse
    {
        $line = $this->workshop->addLine($this->find($id, 'update'), $request->validated());

        return (new WorkshopResource($line))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/workshop/folders/{id}/lines/{lineId}', operationId: 'destroyWorkshopFolderLine', description: 'Remove a line from a folder that is not invoiced.', summary: 'Remove folder line', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId'), new OA\Parameter(ref: '#/components/parameters/WorkshopLineId')], responses: [
        new OA\Response(response: 204, description: 'Line removed'),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function destroyLine(string $id, string $lineId): Response
    {
        $folder = $this->find($id, 'update');
        $this->workshop->removeLine($folder, WorkshopFolderLine::query()->where('folder_id', $folder->id)->findOrFail($lineId));

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/workshop/folders/{id}/status', operationId: 'changeWorkshopFolderStatus', description: 'Move a folder along its workflow: open, in progress, completed, delivered, or cancelled.', summary: 'Change folder status', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderActionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function status(FolderActionRequest $request, string $id): WorkshopResource
    {
        $request->validate(['status' => ['required']]);
        $mileageOut = $request->filled('mileage_out') ? $request->integer('mileage_out') : null;

        return new WorkshopResource($this->workshop->transition($this->find($id, 'update'), (string) $request->validated('status'), $mileageOut));
    }

    #[OA\Post(path: '/api/v1/workshop/folders/{id}/quote', operationId: 'quoteWorkshopFolder', description: 'Create a sales quote from the lines of the folder for the customer to approve.', summary: 'Quote from folder', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderActionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Quote created', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDocumentResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function quote(FolderActionRequest $request, string $id): JsonResponse
    {
        $folder = $this->find($id, 'update');
        $quote = $this->workshop->quote($folder, $request->validated('valid_until'));

        return $this->documentResponse($folder, 'quote', (string) $quote->id, (string) $quote->quote_number);
    }

    #[OA\Post(path: '/api/v1/workshop/folders/{id}/delivery-note', operationId: 'deliveryNoteWorkshopFolder', description: 'Create the delivery note of a completed folder; tracked parts leave the stock.', summary: 'Delivery note from folder', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Delivery note created', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDocumentResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function deliveryNote(string $id): JsonResponse
    {
        $folder = $this->find($id, 'update');
        $note = $this->workshop->deliveryNote($folder);

        return $this->documentResponse($folder, 'delivery_note', (string) $note->id, (string) $note->ddt_number);
    }

    #[OA\Post(path: '/api/v1/workshop/folders/{id}/invoice', operationId: 'invoiceWorkshopFolder', description: 'Invoice a completed or delivered folder once, through the invoicing flow.', summary: 'Invoice folder', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderActionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Invoice issued', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDocumentResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function invoice(FolderActionRequest $request, string $id): JsonResponse
    {
        $folder = $this->find($id, 'bill');
        $invoice = $this->workshop->invoice($folder, (string) ($request->validated('date') ?? now()->toDateString()));

        return $this->documentResponse($folder, 'invoice', (string) $invoice->id, (string) $invoice->invoice_number);
    }

    private function find(string $id, string $ability): WorkshopFolder
    {
        $folder = WorkshopFolder::query()->findOrFail($id);
        $this->authorize($ability, $folder);

        return $folder;
    }

    private function documentResponse(WorkshopFolder $folder, string $type, string $documentId, string $number): JsonResponse
    {
        return response()->json(['data' => [
            'folder_id' => $folder->id,
            'document_type' => $type,
            'document_id' => $documentId,
            'number' => $number,
        ]], Response::HTTP_CREATED);
    }
}
