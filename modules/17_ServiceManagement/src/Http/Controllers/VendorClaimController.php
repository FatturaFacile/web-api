<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Enums\VendorClaimStatus;
use Modules\ServiceManagement\Http\Requests\TransitionVendorClaimRequest;
use Modules\ServiceManagement\Http\Requests\UpdateVendorClaimRequest;
use Modules\ServiceManagement\Http\Resources\VendorClaimResource;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;
use Modules\ServiceManagement\Services\VendorClaimService;
use OpenApi\Attributes as OA;

final class VendorClaimController extends Controller
{
    public function __construct(
        private readonly VendorClaimService $claimService,
    ) {}

    #[OA\Get(
        path: '/api/v1/esm/vendor-claims',
        operationId: 'listEsmVendorClaims',
        description: 'Paginated supplier warranty claims.',
        summary: 'List vendor claims',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Vendor Claims'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Claim status', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'supplier_id', in: 'query', required: false, description: 'Original supplier', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'rma_order_id', in: 'query', required: false, description: 'RMA order', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Paginated claims', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VendorWarrantyClaim::class);

        $claims = VendorWarrantyClaim::query()
            ->with(['rmaOrder', 'supplier'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('supplier_id'), fn ($query) => $query->where('original_supplier_id', $request->query('supplier_id')))
            ->when($request->filled('rma_order_id'), fn ($query) => $query->where('rma_order_id', $request->query('rma_order_id')))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => VendorClaimResource::collection($claims->getCollection()),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/esm/vendor-claims/{vendorClaim}',
        operationId: 'showEsmVendorClaim',
        description: 'Returns one vendor warranty claim.',
        summary: 'Show vendor claim',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Vendor Claims'],
        parameters: [new OA\Parameter(name: 'vendorClaim', in: 'path', required: true, description: 'Identifier of the vendor warranty claim', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Vendor claim', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
    )]
    public function show(VendorWarrantyClaim $vendorClaim): JsonResponse
    {
        $this->authorize('view', $vendorClaim);

        return response()->json(['data' => new VendorClaimResource($vendorClaim->load(['rmaOrder', 'supplier']))]);
    }

    #[OA\Patch(
        path: '/api/v1/esm/vendor-claims/{vendorClaim}',
        operationId: 'updateEsmVendorClaim',
        description: 'Updates supplier reference, notes and claimed amount.',
        summary: 'Update vendor claim',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Vendor Claims'],
        parameters: [new OA\Parameter(name: 'vendorClaim', in: 'path', required: true, description: 'Identifier of the vendor warranty claim', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'supplier_rma_reference', type: 'string', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true), new OA\Property(property: 'claimed_amount', type: 'number', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Claim updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function update(UpdateVendorClaimRequest $request, VendorWarrantyClaim $vendorClaim): JsonResponse
    {
        $this->authorize('update', $vendorClaim);

        $updated = $this->claimService->update($vendorClaim, $request->validated());

        return response()->json([
            'message' => 'Vendor claim updated successfully',
            'data' => new VendorClaimResource($updated),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esm/vendor-claims/{vendorClaim}/transition',
        operationId: 'transitionEsmVendorClaim',
        description: 'Moves the claim along its workflow.',
        summary: 'Transition vendor claim',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Vendor Claims'],
        parameters: [new OA\Parameter(name: 'vendorClaim', in: 'path', required: true, description: 'Identifier of the vendor warranty claim', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['to'], properties: [new OA\Property(property: 'to', type: 'string', enum: ['submitted', 'acknowledged', 'approved_credit_note', 'approved_replacement', 'rejected', 'closed']), new OA\Property(property: 'credit_note_reference', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Claim transitioned', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
    )]
    public function transition(TransitionVendorClaimRequest $request, VendorWarrantyClaim $vendorClaim): JsonResponse
    {
        $this->authorize('transition', $vendorClaim);

        $updated = $this->claimService->transition(
            $vendorClaim,
            VendorClaimStatus::from((string) $request->validated('to')),
            $request->validated('credit_note_reference'),
            $request->validated('note'),
        );

        return response()->json([
            'message' => 'Vendor claim moved to '.$updated->status,
            'data' => new VendorClaimResource($updated),
        ]);
    }
}
