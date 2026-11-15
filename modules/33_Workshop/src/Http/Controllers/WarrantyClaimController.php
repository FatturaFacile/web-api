<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\SaveWarrantyClaimRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\WorkshopWarrantyClaim;
use Modules\Workshop\Services\WarrantyClaimService;
use OpenApi\Attributes as OA;

/**
 * Warranty claims against the supplier of a part or service used on a work folder, and their reimbursement.
 */
final class WarrantyClaimController extends Controller
{
    public function __construct(private readonly WarrantyClaimService $claims) {}

    /**
     * Return the warranty claims, newest first, optionally by status or folder.
     */
    #[OA\Get(path: '/api/v1/workshop/warranty-claims', operationId: 'listWorkshopWarrantyClaims', description: 'Return the warranty claims, newest first, optionally by status or folder.', summary: 'List warranty claims', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'submitted', 'accepted', 'rejected', 'reimbursed'])),
        new OA\Parameter(name: 'folder_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Claims', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkshopWarrantyClaim::class);

        $claims = WorkshopWarrantyClaim::query()
            ->with('folder')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('folder_id'), fn ($query) => $query->where('folder_id', $request->string('folder_id')->toString()))
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 25), 100)));

        return WorkshopResource::collection($claims);
    }

    /**
     * Open a draft warranty claim on a folder.
     */
    #[OA\Post(path: '/api/v1/workshop/warranty-claims', operationId: 'storeWorkshopWarrantyClaim', description: 'Open a draft warranty claim on a work folder, optionally against one of its lines (the line gives the product and the default amount).', summary: 'Open warranty claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopWarrantyClaimRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Claim opened', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(SaveWarrantyClaimRequest $request): JsonResponse
    {
        $this->authorize('create', WorkshopWarrantyClaim::class);

        return (new WorkshopResource($this->claims->create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a warranty claim.
     */
    #[OA\Get(path: '/api/v1/workshop/warranty-claims/{id}', operationId: 'showWorkshopWarrantyClaim', description: 'Return a warranty claim with its folder.', summary: 'Show warranty claim', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Claim', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function show(string $id): WorkshopResource
    {
        return new WorkshopResource($this->find($id, 'view')->load('folder'));
    }

    /**
     * Change a draft warranty claim.
     */
    #[OA\Put(path: '/api/v1/workshop/warranty-claims/{id}', operationId: 'updateWorkshopWarrantyClaim', description: 'Change a draft warranty claim.', summary: 'Change warranty claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopWarrantyClaimRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Claim changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function update(SaveWarrantyClaimRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->claims->update($this->find($id, 'update'), $request->validated()));
    }

    /**
     * Submit the claim to the supplier.
     */
    #[OA\Post(path: '/api/v1/workshop/warranty-claims/{id}/submit', operationId: 'submitWorkshopWarrantyClaim', description: 'Submit a draft claim to the supplier. The claim needs a supplier and an amount.', summary: 'Submit warranty claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopWarrantySubmitRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Claim submitted', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function submit(SaveWarrantyClaimRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->claims->submit($this->find($id, 'update'), $request->validated('supplier_reference')));
    }

    /**
     * Record that the supplier accepted the claim.
     */
    #[OA\Post(path: '/api/v1/workshop/warranty-claims/{id}/accept', operationId: 'acceptWorkshopWarrantyClaim', description: 'Record that the supplier accepted a submitted claim.', summary: 'Accept warranty claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDecisionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Claim accepted', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function accept(SaveWarrantyClaimRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->claims->accept($this->find($id, 'update'), $request->validated('note')));
    }

    /**
     * Record that the supplier rejected the claim.
     */
    #[OA\Post(path: '/api/v1/workshop/warranty-claims/{id}/reject', operationId: 'rejectWorkshopWarrantyClaim', description: 'Record that the supplier rejected a submitted claim, with the reason.', summary: 'Reject warranty claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopDecisionRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Claim rejected', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function reject(SaveWarrantyClaimRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->claims->reject($this->find($id, 'update'), $request->validated('note')));
    }

    /**
     * Record the supplier reimbursement of an accepted claim.
     */
    #[OA\Post(path: '/api/v1/workshop/warranty-claims/{id}/reimburse', operationId: 'reimburseWorkshopWarrantyClaim', description: 'Record the amount the supplier reimbursed on an accepted claim. It cannot exceed the claimed amount.', summary: 'Reimburse warranty claim', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopWarrantyReimburseRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Claim reimbursed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function reimburse(SaveWarrantyClaimRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->claims->reimburse($this->find($id, 'bill'), (string) $request->validated('reimbursed_amount'), (string) $request->validated('reimbursed_on')));
    }

    private function find(string $id, string $ability): WorkshopWarrantyClaim
    {
        $claim = WorkshopWarrantyClaim::query()->findOrFail($id);
        $this->authorize($ability, $claim);

        return $claim;
    }
}
