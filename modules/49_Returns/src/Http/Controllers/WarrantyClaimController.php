<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Http\Requests\ClaimAcceptRequest;
use Modules\Returns\Http\Requests\ReasonRequest;
use Modules\Returns\Http\Requests\WarrantyClaimRequest;
use Modules\Returns\Http\Resources\WarrantyClaimResource;
use Modules\Returns\Models\WarrantyClaim;
use Modules\Returns\Policies\ReturnsPolicy;
use Modules\Returns\Services\WarrantyService;
use OpenApi\Attributes as OA;

/**
 * Warranty claims: raised on a warranty with its validity frozen, decided by an approver and resolved.
 */
final class WarrantyClaimController extends Controller
{
    use InteractsWithReturns;

    public function __construct(private readonly WarrantyService $warranties) {}

    #[OA\Get(path: '/api/v1/returns/claims', operationId: 'listReturnsClaims', description: 'Warranty claims, newest first. Filters: status, warranty_id, rma_id.', summary: 'List warranty claims', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Claims', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsWarrantyClaim'))])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WarrantyClaim::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(WarrantyClaim::Statuses)],
            'warranty_id' => ['nullable', 'uuid'],
            'rma_id' => ['nullable', 'uuid'],
        ]);

        return WarrantyClaimResource::collection(
            WarrantyClaim::query()
                ->with(['warranty', 'rma'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['warranty_id']), static fn ($query) => $query->where('warranty_id', $filters['warranty_id']))
                ->when(isset($filters['rma_id']), static fn ($query) => $query->where('rma_id', $filters['rma_id']))
                ->orderByDesc('claim_date')
                ->orderByDesc('claim_number')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/returns/claims', operationId: 'storeReturnsClaim', description: 'Raise a claim on a warranty, optionally linked to the return that brings the goods back. The claim freezes whether the warranty was valid on the claim date.', summary: 'Raise a warranty claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsWarrantyClaimRequest')), tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Claim raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarrantyClaim')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function store(WarrantyClaimRequest $request): JsonResponse
    {
        $this->authorize('create', WarrantyClaim::class);
        $claim = $this->warranties->createClaim($this->tenantId(), $request->validated(), $this->userId());

        return (new WarrantyClaimResource($claim->load(['warranty', 'rma'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/returns/claims/{id}', operationId: 'showReturnsClaim', description: 'Return a warranty claim.', summary: 'Show a warranty claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Claim', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarrantyClaim')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
    ])]
    public function show(string $id): WarrantyClaimResource
    {
        $claim = WarrantyClaim::query()->with(['warranty', 'rma'])->findOrFail($id);
        $this->authorize('view', $claim);

        return new WarrantyClaimResource($claim);
    }

    #[OA\Post(path: '/api/v1/returns/claims/{id}/accept', operationId: 'acceptReturnsClaim', description: 'Accept an open claim. A claim the warranty did not cover is refused unless an approver sets override_validity. Reserved to approvers.', summary: 'Accept a warranty claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsClaimAcceptRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Claim accepted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarrantyClaim')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function accept(ClaimAcceptRequest $request, string $id): WarrantyClaimResource
    {
        $claim = WarrantyClaim::query()->findOrFail($id);
        $this->authorize('approve', $claim);
        $data = $request->validated();
        $override = (bool) ($data['override_validity'] ?? false) && app(ReturnsPolicy::class)->mayApprove($request->user());

        return new WarrantyClaimResource($this->warranties->accept($claim, $data['notes'] ?? null, $override)->load(['warranty', 'rma']));
    }

    #[OA\Post(path: '/api/v1/returns/claims/{id}/reject', operationId: 'rejectReturnsClaim', description: 'Reject an open claim with the reason. Reserved to approvers.', summary: 'Reject a warranty claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsReasonRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Claim rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarrantyClaim')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function reject(ReasonRequest $request, string $id): WarrantyClaimResource
    {
        $claim = WarrantyClaim::query()->findOrFail($id);
        $this->authorize('approve', $claim);

        return new WarrantyClaimResource($this->warranties->reject($claim, (string) $request->validated()['reason'])->load(['warranty', 'rma']));
    }

    #[OA\Post(path: '/api/v1/returns/claims/{id}/resolve', operationId: 'resolveReturnsClaim', description: 'Mark an accepted claim as resolved (the goods were repaired, replaced or refunded).', summary: 'Resolve a warranty claim', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Claim resolved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarrantyClaim')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function resolve(string $id): WarrantyClaimResource
    {
        $claim = WarrantyClaim::query()->findOrFail($id);
        $this->authorize('update', $claim);

        return new WarrantyClaimResource($this->warranties->resolve($claim)->load(['warranty', 'rma']));
    }
}
