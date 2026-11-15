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
use Modules\Returns\Http\Requests\ReasonRequest;
use Modules\Returns\Http\Requests\WarrantyRequest;
use Modules\Returns\Http\Resources\WarrantyResource;
use Modules\Returns\Models\Warranty;
use Modules\Returns\Services\WarrantyService;
use OpenApi\Attributes as OA;

/**
 * The warranty registry: units and batches sold with a warranty, their validity and the expiring ones.
 */
final class WarrantyController extends Controller
{
    use InteractsWithReturns;

    public function __construct(private readonly WarrantyService $warranties) {}

    #[OA\Get(path: '/api/v1/returns/warranties', operationId: 'listReturnsWarranties', description: 'Warranties by end date. Filters: status, product_id, contact_id, search (serial or lot number), expiring (days: active warranties ending within that many days from today), expired (true: active warranties already past their end date).', summary: 'List warranties', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Warranties', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsWarranty'))])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Warranty::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Warranty::Statuses)],
            'product_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'expiring' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'expired' => ['nullable', 'boolean'],
        ]);
        $today = now()->toDateString();

        return WarrantyResource::collection(
            Warranty::query()
                ->with(['product', 'contact'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['product_id']), static fn ($query) => $query->where('product_id', $filters['product_id']))
                ->when(isset($filters['contact_id']), static fn ($query) => $query->where('contact_id', $filters['contact_id']))
                ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner
                    ->where('serial_number', 'like', '%'.$filters['search'].'%')
                    ->orWhere('lot_number', 'like', '%'.$filters['search'].'%')))
                ->when(isset($filters['expiring']), static fn ($query) => $query
                    ->where('status', Warranty::Active)
                    ->whereBetween('ends_on', [$today, now()->addDays((int) $filters['expiring'])->toDateString()]))
                ->when($request->boolean('expired'), static fn ($query) => $query->where('status', Warranty::Active)->where('ends_on', '<', $today))
                ->orderBy('ends_on')
                ->orderBy('serial_number')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/returns/warranties', operationId: 'storeReturnsWarranty', description: 'Register the warranty of a unit (serial number) or a batch (lot number) sold to a customer. A serial number has one active warranty per product.', summary: 'Register a warranty', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsWarrantyRequest')), tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Warranty registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarranty')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function store(WarrantyRequest $request): JsonResponse
    {
        $this->authorize('create', Warranty::class);
        $warranty = $this->warranties->create($this->tenantId(), $request->validated());

        return (new WarrantyResource($warranty->load(['product', 'contact'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/returns/warranties/{id}', operationId: 'showReturnsWarranty', description: 'Return a warranty with its validity today.', summary: 'Show a warranty', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Warranty', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarranty')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
    ])]
    public function show(string $id): WarrantyResource
    {
        $warranty = Warranty::query()->with(['product', 'contact'])->findOrFail($id);
        $this->authorize('view', $warranty);

        return new WarrantyResource($warranty);
    }

    #[OA\Put(path: '/api/v1/returns/warranties/{id}', operationId: 'updateReturnsWarranty', description: 'Change the period and the coverage of an active warranty. The product, the customer and the unit are fixed: a wrong warranty is voided and registered again.', summary: 'Update a warranty', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsWarrantyRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Warranty updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarranty')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function update(WarrantyRequest $request, string $id): WarrantyResource
    {
        $warranty = Warranty::query()->findOrFail($id);
        $this->authorize('update', $warranty);

        return new WarrantyResource($this->warranties->update($warranty, $request->validated())->load(['product', 'contact']));
    }

    #[OA\Post(path: '/api/v1/returns/warranties/{id}/void', operationId: 'voidReturnsWarranty', description: 'Void a warranty (registered by mistake, sold unit returned and refunded). A void warranty covers nothing.', summary: 'Void a warranty', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReturnsReasonRequest')), tags: ['Returns'], parameters: [new OA\Parameter(ref: '#/components/parameters/ReturnsId')], responses: [
        new OA\Response(response: 200, description: 'Warranty voided', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarranty')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function void(ReasonRequest $request, string $id): WarrantyResource
    {
        $warranty = Warranty::query()->findOrFail($id);
        $this->authorize('update', $warranty);

        return new WarrantyResource($this->warranties->void($warranty, (string) $request->validated()['reason'])->load(['product', 'contact']));
    }

    #[OA\Get(path: '/api/v1/returns/warranties/{id}/validity', operationId: 'checkReturnsWarrantyValidity', description: 'Check whether the warranty covers a claim on a date (default today): valid, or the reason it is not (void, not_started, expired), with the days left.', summary: 'Check the validity of a warranty', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/ReturnsId'),
        new OA\Parameter(name: 'on', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Validity', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ReturnsWarrantyValidity')])),
        new OA\Response(ref: '#/components/responses/ReturnsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ReturnsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ReturnsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ReturnsUnprocessable', response: 422),
    ])]
    public function validity(Request $request, string $id): JsonResponse
    {
        $warranty = Warranty::query()->findOrFail($id);
        $this->authorize('view', $warranty);
        $filters = $request->validate(['on' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->warranties->validity($warranty, $filters['on'] ?? null)]);
    }
}
