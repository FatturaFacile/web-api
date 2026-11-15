<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Actions\ApplyLandedCostDocumentAction;
use Modules\Inventory\Actions\CreateLandedCostDocumentAction;
use Modules\Inventory\Actions\ReverseLandedCostDocumentAction;
use Modules\Inventory\Contracts\LandedCostAllocationServiceInterface;
use Modules\Inventory\DTOs\LandedCostAllocationLine;
use Modules\Inventory\Enums\LandedCostAllocationKey;
use Modules\Inventory\Enums\LandedCostStatus;
use Modules\Inventory\Http\Requests\ReverseLandedCostDocumentRequest;
use Modules\Inventory\Http\Requests\StoreLandedCostDocumentRequest;
use Modules\Inventory\Http\Resources\LandedCostDocumentResource;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Support\Decimal;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Landed Costs', description: 'Freight, customs duty, insurance and brokerage allocated onto goods receipts')]
final class LandedCostDocumentController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/landed-costs',
        summary: 'List landed cost documents',
        tags: ['Inventory Landed Costs'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'applied', 'reversed'])),
            new OA\Parameter(name: 'receipt_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated landed cost documents', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLandedCostDocument')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List landed cost documents', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LandedCostDocument::class);

        $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', LandedCostStatus::values())],
            'receipt_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = LandedCostDocument::query()->orderByDesc('document_date')->orderByDesc('number');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('receipt_id')) {
            $query->whereHas('receipts', fn ($q) => $q->where('purchase_receipt_id', $request->query('receipt_id')));
        }
        if ($request->filled('from')) {
            $query->whereDate('document_date', '>=', (string) $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('document_date', '<=', (string) $request->query('to'));
        }

        $documents = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(LandedCostDocumentResource::collection($documents)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/landed-costs',
        summary: 'Create draft landed cost document',
        description: 'Creates a draft document targeting goods receipts; the response includes the allocation preview.',
        tags: ['Inventory Landed Costs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostInput')),
        responses: [
            new OA\Response(response: 201, description: 'Draft created', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostDocument')),
            new OA\Response(response: 403, description: 'Missing inventory.valuation.manage'),
            new OA\Response(response: 422, description: 'Validation failed (no basis, missing weight/volume, receipt without layers)'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create draft landed cost document', tags: ['Inventory'])]
    public function store(StoreLandedCostDocumentRequest $request, CreateLandedCostDocumentAction $action): JsonResponse
    {
        $this->authorize('create', LandedCostDocument::class);

        $dto = $request->toDTO();
        $document = $action->execute($dto);
        $preview = $action->preview($dto);

        return response()->json((new LandedCostDocumentResource($document))->withPreview($preview['allocations']), 201);
    }

    #[OA\Post(
        path: '/api/v1/inventory/landed-costs/preview',
        summary: 'Preview landed cost allocation',
        description: 'Computes the allocation (largest remainder, sums exactly to total) without persisting anything.',
        tags: ['Inventory Landed Costs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostInput')),
        responses: [
            new OA\Response(response: 200, description: 'Allocation preview', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostPreview')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Preview landed cost allocation', tags: ['Inventory'])]
    public function preview(StoreLandedCostDocumentRequest $request, CreateLandedCostDocumentAction $action): JsonResponse
    {
        $this->authorize('viewAny', LandedCostDocument::class);

        $preview = $action->preview($request->toDTO());

        return response()->json([
            'allocations' => array_map(
                static fn (LandedCostAllocationLine $line): array => LandedCostDocumentResource::allocationFromLine($line),
                $preview['allocations']
            ),
            'totals' => $preview['totals'],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/landed-costs/{id}',
        summary: 'Get landed cost document',
        tags: ['Inventory Landed Costs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Landed cost document', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostDocument')),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get landed cost document', tags: ['Inventory'])]
    public function show(string $id, LandedCostAllocationServiceInterface $allocationService): JsonResponse
    {
        $document = LandedCostDocument::query()->findOrFail($id);
        $this->authorize('view', $document);

        $resource = new LandedCostDocumentResource($document);
        if ($document->isDraft()) {
            $resource->withPreview($this->livePreview($document, $allocationService));
        }

        return response()->json($resource);
    }

    #[OA\Post(
        path: '/api/v1/inventory/landed-costs/{id}/apply',
        summary: 'Apply landed cost document',
        description: 'Persists allocations, uplifts cost layers / weighted average cost and posts the balanced inventory/COGS journal.',
        tags: ['Inventory Landed Costs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Applied', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostDocument')),
            new OA\Response(response: 403, description: 'Missing inventory.valuation.manage or segregation of duties'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Document is not draft'),
            new OA\Response(response: 422, description: 'Fiscal period closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Apply landed cost document', tags: ['Inventory'])]
    public function apply(string $id, ApplyLandedCostDocumentAction $action): JsonResponse
    {
        $document = LandedCostDocument::query()->findOrFail($id);
        $this->authorize('apply', $document);

        $applied = $action->execute((string) $document->id, $this->actorId());

        return response()->json(new LandedCostDocumentResource($applied));
    }

    #[OA\Post(
        path: '/api/v1/inventory/landed-costs/{id}/reverse',
        summary: 'Reverse landed cost document',
        description: 'Creates a reversal document (negative amounts) and the reversal journal; the original becomes reversed.',
        tags: ['Inventory Landed Costs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Reversal document', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLandedCostDocument')),
            new OA\Response(response: 403, description: 'Missing inventory.valuation.manage'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Document is not applied'),
            new OA\Response(response: 422, description: 'Validation failed or fiscal period closed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Reverse landed cost document', tags: ['Inventory'])]
    public function reverse(string $id, ReverseLandedCostDocumentRequest $request, ReverseLandedCostDocumentAction $action): JsonResponse
    {
        $document = LandedCostDocument::query()->findOrFail($id);
        $this->authorize('reverse', $document);

        $reversal = $action->execute((string) $document->id, $request->string('reason')->value(), $this->actorId());

        return response()->json(new LandedCostDocumentResource($reversal));
    }

    /**
     * @return list<LandedCostAllocationLine>
     */
    private function livePreview(LandedCostDocument $document, LandedCostAllocationServiceInterface $allocationService): array
    {
        $total = Decimal::money(Decimal::mul((string) $document->total_amount, (string) $document->exchange_rate));

        try {
            return $allocationService->allocate(
                LandedCostAllocationKey::from($document->allocation_key),
                $total,
                $document->receiptIds()
            );
        } catch (ValidationException) {
            // The draft is no longer allocatable (targets changed); apply will report the 422.
            return [];
        }
    }

    private function actorId(): ?string
    {
        $id = auth()->id();

        return $id !== null ? (string) $id : null;
    }
}
