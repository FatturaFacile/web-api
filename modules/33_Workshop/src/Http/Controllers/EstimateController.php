<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\SaveEstimateRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\WorkshopEstimate;
use Modules\Workshop\Models\WorkshopEstimateLine;
use Modules\Workshop\Services\EstimateService;
use OpenApi\Attributes as OA;

/**
 * Workshop estimates: draft with lines, sent to the customer, approved or rejected, converted into a work folder.
 */
final class EstimateController extends Controller
{
    public function __construct(private readonly EstimateService $estimates) {}

    /**
     * Return the estimates, newest first, optionally by status, vehicle or search term.
     */
    #[OA\Get(path: '/api/v1/workshop/estimates', operationId: 'listWorkshopEstimates', description: 'Return the workshop estimates, newest first, optionally by status, vehicle or a term matching the number, plate or customer.', summary: 'List estimates', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'sent', 'approved', 'rejected', 'converted'])),
        new OA\Parameter(name: 'vehicle_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Estimates', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkshopEstimate::class);

        $term = '%'.trim($request->string('search')->toString()).'%';
        $estimates = WorkshopEstimate::query()
            ->with(['vehicle', 'contact'])
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($inner) => $inner->whereLike('estimate_number', $term)
                ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->whereLike('license_plate', $term))
                ->orWhereHas('contact', fn ($contact) => $contact->whereLike('company_name', $term)->orWhereLike('last_name', $term))))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('vehicle_id'), fn ($query) => $query->where('vehicle_id', $request->string('vehicle_id')->toString()))
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 25), 100)));

        return WorkshopResource::collection($estimates);
    }

    /**
     * Create a draft estimate, with its lines when given.
     */
    #[OA\Post(path: '/api/v1/workshop/estimates', operationId: 'storeWorkshopEstimate', description: 'Create a draft estimate for a vehicle of the company. The request may carry the labour and part lines; the totals are calculated by the API.', summary: 'Create estimate', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopEstimateRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Estimate created', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(SaveEstimateRequest $request): JsonResponse
    {
        $this->authorize('create', WorkshopEstimate::class);
        $estimate = $this->estimates->create($request->validated());

        return (new WorkshopResource($estimate->load(['lines', 'vehicle', 'contact'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return an estimate with its lines.
     */
    #[OA\Get(path: '/api/v1/workshop/estimates/{id}', operationId: 'showWorkshopEstimate', description: 'Return an estimate with its lines, vehicle and customer.', summary: 'Show estimate', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Estimate', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function show(string $id): WorkshopResource
    {
        return new WorkshopResource($this->find($id, 'view')->load(['lines', 'vehicle', 'contact']));
    }

    /**
     * Change the data of a draft estimate.
     */
    #[OA\Put(path: '/api/v1/workshop/estimates/{id}', operationId: 'updateWorkshopEstimate', description: 'Change the data of a draft estimate. Lines have their own endpoints.', summary: 'Change estimate', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopEstimateRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Estimate changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function update(SaveEstimateRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->estimates->update($this->find($id, 'update'), $request->validated())->load('lines'));
    }

    /**
     * Delete a draft estimate.
     */
    #[OA\Delete(path: '/api/v1/workshop/estimates/{id}', operationId: 'destroyWorkshopEstimate', description: 'Delete a draft estimate. A sent or decided estimate is kept as the record of what was offered.', summary: 'Delete estimate', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 204, description: 'Estimate deleted'),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $this->estimates->delete($this->find($id, 'delete'));

        return response()->noContent();
    }

    /**
     * Add a line to a draft estimate.
     */
    #[OA\Post(path: '/api/v1/workshop/estimates/{id}/lines', operationId: 'storeWorkshopEstimateLine', description: 'Add labour, a part, paint material or sublet work to a draft estimate.', summary: 'Add estimate line', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopFolderLineRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Line added', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function storeLine(SaveEstimateRequest $request, string $id): JsonResponse
    {
        $line = $this->estimates->addLine($this->find($id, 'update'), $request->validated());

        return (new WorkshopResource($line))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Remove a line of a draft estimate.
     */
    #[OA\Delete(path: '/api/v1/workshop/estimates/{id}/lines/{lineId}', operationId: 'destroyWorkshopEstimateLine', description: 'Remove a line of a draft estimate.', summary: 'Remove estimate line', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId'), new OA\Parameter(ref: '#/components/parameters/WorkshopLineId')], responses: [
        new OA\Response(response: 204, description: 'Line removed'),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function destroyLine(string $id, string $lineId): Response
    {
        $this->estimates->removeLine($this->find($id, 'update'), WorkshopEstimateLine::query()->findOrFail($lineId));

        return response()->noContent();
    }

    /**
     * Send a draft estimate to the customer.
     */
    #[OA\Post(path: '/api/v1/workshop/estimates/{id}/send', operationId: 'sendWorkshopEstimate', description: 'Mark a draft estimate with lines as sent to the customer. From then on its lines cannot change.', summary: 'Send estimate', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Estimate sent', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function send(string $id): WorkshopResource
    {
        return new WorkshopResource($this->estimates->send($this->find($id, 'update'))->load('lines'));
    }

    /**
     * Record the approval of the customer.
     */
    #[OA\Post(path: '/api/v1/workshop/estimates/{id}/approve', operationId: 'approveWorkshopEstimate', description: 'Record the approval of the customer on a sent estimate that has not expired.', summary: 'Approve estimate', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDecisionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Estimate approved', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function approve(SaveEstimateRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->estimates->approve($this->find($id, 'update'), $request->validated('note'))->load('lines'));
    }

    /**
     * Record the rejection of the customer.
     */
    #[OA\Post(path: '/api/v1/workshop/estimates/{id}/reject', operationId: 'rejectWorkshopEstimate', description: 'Record the rejection of the customer on a sent estimate, with the reason.', summary: 'Reject estimate', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDecisionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Estimate rejected', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function reject(SaveEstimateRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->estimates->reject($this->find($id, 'update'), $request->validated('note'))->load('lines'));
    }

    /**
     * Convert an approved estimate into a work folder.
     */
    #[OA\Post(path: '/api/v1/workshop/estimates/{id}/convert', operationId: 'convertWorkshopEstimate', description: 'Open a work folder from an approved estimate, with the same lines. The estimate becomes converted and keeps the folder id.', summary: 'Convert estimate to folder', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Folder opened', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function convert(string $id): JsonResponse
    {
        $this->authorize('create', WorkshopEstimate::class);
        $folder = $this->estimates->convert($this->find($id, 'update'));

        return (new WorkshopResource($folder))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(string $id, string $ability): WorkshopEstimate
    {
        $estimate = WorkshopEstimate::query()->findOrFail($id);
        $this->authorize($ability, $estimate);

        return $estimate;
    }
}
