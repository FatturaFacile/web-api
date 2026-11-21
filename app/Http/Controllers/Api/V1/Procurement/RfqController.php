<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Application\Procurement\Rfq\Commands\AwardRfqCommand;
use App\Application\Procurement\Rfq\Commands\CancelRfqCommand;
use App\Application\Procurement\Rfq\Commands\CreateRfqCommand;
use App\Application\Procurement\Rfq\Commands\CreateRfqFromRequisitionsCommand;
use App\Application\Procurement\Rfq\Commands\ExtendRfqDeadlineCommand;
use App\Application\Procurement\Rfq\Commands\PublishAndDispatchRfqCommand;
use App\Application\Procurement\Rfq\Commands\UpdateRfqCommand;
use App\Application\Procurement\Rfq\Handlers\AwardRfqHandler;
use App\Application\Procurement\Rfq\Handlers\CancelRfqHandler;
use App\Application\Procurement\Rfq\Handlers\CreateRfqFromRequisitionsHandler;
use App\Application\Procurement\Rfq\Handlers\CreateRfqHandler;
use App\Application\Procurement\Rfq\Handlers\ExtendRfqDeadlineHandler;
use App\Application\Procurement\Rfq\Handlers\GetBidComparisonMatrixHandler;
use App\Application\Procurement\Rfq\Handlers\GetRfqDetailHandler;
use App\Application\Procurement\Rfq\Handlers\ListRfqsHandler;
use App\Application\Procurement\Rfq\Handlers\PublishAndDispatchRfqHandler;
use App\Application\Procurement\Rfq\Handlers\UpdateRfqHandler;
use App\Application\Procurement\Rfq\Queries\GetBidComparisonMatrixQuery;
use App\Application\Procurement\Rfq\Queries\GetRfqDetailQuery;
use App\Application\Procurement\Rfq\Queries\ListRfqsQuery;
use App\Domain\Procurement\Rfq\Enums\AwardMode;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\Rfq\AwardRfqRequest;
use App\Http\Requests\Procurement\Rfq\CancelRfqRequest;
use App\Http\Requests\Procurement\Rfq\CreateRfqFromRequisitionsRequest;
use App\Http\Requests\Procurement\Rfq\ExtendRfqDeadlineRequest;
use App\Http\Requests\Procurement\Rfq\StoreRfqRequest;
use App\Http\Requests\Procurement\Rfq\UpdateRfqRequest;
use App\Http\Resources\Procurement\Rfq\BidComparisonMatrixResource;
use App\Http\Resources\Procurement\Rfq\RfqResource;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Requests\StoreSupplierQuoteRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\SupplierQuoteResource;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Services\RfqService;
use OpenApi\Attributes as OA;

/**
 * Buyer API controller for Module 045 (Request for Quotation, Comparison Matrix & Awarding).
 */
#[OA\Tag(name: 'Procurement RFQ', description: 'Requests for quotation, supplier bidding, comparison matrix, and awarding')]
final class RfqController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListRfqsHandler $listHandler,
        private readonly GetRfqDetailHandler $detailHandler,
        private readonly CreateRfqHandler $createHandler,
        private readonly CreateRfqFromRequisitionsHandler $fromRequisitionsHandler,
        private readonly UpdateRfqHandler $updateHandler,
        private readonly PublishAndDispatchRfqHandler $publishHandler,
        private readonly ExtendRfqDeadlineHandler $extendDeadlineHandler,
        private readonly GetBidComparisonMatrixHandler $matrixHandler,
        private readonly AwardRfqHandler $awardHandler,
        private readonly CancelRfqHandler $cancelHandler,
        private readonly RfqService $legacyRfqService,
    ) {}

    /**
     * List requests for quotation.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs',
        operationId: 'procurementRfqsIndex',
        summary: 'List requests for quotation',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Paginated RFQs'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RequestForQuotation::class);

        $paginator = $this->listHandler->handle(new ListRfqsQuery(
            status: $request->filled('status') ? (string) $request->query('status') : null,
            buyerUserId: $request->filled('buyer_user_id') ? (string) $request->query('buyer_user_id') : null,
            dateFrom: $request->filled('date_from') ? (string) $request->query('date_from') : null,
            dateTo: $request->filled('date_to') ? (string) $request->query('date_to') : null,
            search: $request->filled('search') ? (string) $request->query('search') : null,
            perPage: max(1, min(100, (int) $request->query('per_page', '25'))),
        ));

        return RfqResource::collection($paginator);
    }

    /**
     * Create a draft request for quotation.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs',
        operationId: 'procurementRfqsStore',
        summary: 'Create request for quotation',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'RFQ created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(StoreRfqRequest $request): JsonResponse
    {
        $this->authorize('create', RequestForQuotation::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            /** @var array<string, mixed> $line */
            $lines[] = [
                'source_requisition_line_id' => $line['source_requisition_line_id'] ?? null,
                'item_id' => $line['item_id'] ?? null,
                'custom_item_code' => $line['custom_item_code'] ?? null,
                'internal_sku' => $line['internal_sku'] ?? null,
                'description' => (string) $line['description'],
                'technical_specifications' => $line['technical_specifications'] ?? null,
                'quantity' => $line['quantity'],
                'uom' => (string) ($line['unit_of_measure'] ?? $line['uom'] ?? 'PCS'),
                'target_unit_price' => $line['target_unit_price'] ?? null,
                'requested_delivery_date' => $line['requested_delivery_date'] ?? $line['required_by_date'] ?? null,
            ];
        }

        $rfq = $this->createHandler->handle(new CreateRfqCommand(
            tenantId: (string) $this->tenantContext->getTenantId(),
            actorUserId: $this->actorId($request),
            title: (string) $data['title'],
            description: $data['description'] ?? null,
            currencyCode: (string) ($data['currency_code'] ?? $data['currency'] ?? ''),
            incoterms: $data['incoterms'] ?? null,
            submissionDeadline: (string) ($data['submission_deadline'] ?? $data['response_deadline']),
            expectedDeliveryDate: $data['expected_delivery_date'] ?? null,
            evaluationWeights: isset($data['evaluation_weights']) && is_array($data['evaluation_weights']) ? $data['evaluation_weights'] : null,
            sealedBidding: (bool) ($data['sealed_bidding'] ?? false),
            purchaseRequisitionId: $data['purchase_requisition_id'] ?? null,
            costCenterId: $data['cost_center_id'] ?? null,
            notes: $data['notes'] ?? null,
            supplierIds: array_values(array_map('strval', (array) $data['supplier_ids'])),
            lines: $lines,
        ));

        return (new RfqResource($rfq))->response()->setStatusCode(201);
    }

    /**
     * Create a draft RFQ by importing & optionally consolidating approved Purchase Requisitions (Mod 044).
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/from-requisitions',
        operationId: 'procurementRfqsFromRequisitions',
        summary: 'Create RFQ from approved Purchase Requisitions',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'RFQ created from requisitions'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function fromRequisitions(CreateRfqFromRequisitionsRequest $request): JsonResponse
    {
        $this->authorize('create', RequestForQuotation::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $rfq = $this->fromRequisitionsHandler->handle(new CreateRfqFromRequisitionsCommand(
            tenantId: (string) $this->tenantContext->getTenantId(),
            actorUserId: $this->actorId($request),
            purchaseRequisitionIds: array_values(array_map('strval', (array) $data['purchase_requisition_ids'])),
            lineIds: isset($data['line_ids']) ? array_values(array_map('strval', (array) $data['line_ids'])) : null,
            title: (string) $data['title'],
            description: $data['description'] ?? null,
            currencyCode: $data['currency_code'] ?? null,
            incoterms: $data['incoterms'] ?? null,
            submissionDeadline: (string) $data['submission_deadline'],
            expectedDeliveryDate: $data['expected_delivery_date'] ?? null,
            evaluationWeights: isset($data['evaluation_weights']) && is_array($data['evaluation_weights']) ? $data['evaluation_weights'] : null,
            sealedBidding: (bool) ($data['sealed_bidding'] ?? false),
            consolidateMatchingItems: (bool) ($data['consolidate_matching_items'] ?? false),
            supplierIds: array_values(array_map('strval', (array) $data['supplier_ids'])),
            notes: $data['notes'] ?? null,
        ));

        return (new RfqResource($rfq))->response()->setStatusCode(201);
    }

    /**
     * Show one RFQ aggregate with lines, attachments, invitations, and bids.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs/{rfq}',
        operationId: 'procurementRfqsShow',
        summary: 'Show request for quotation',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'RFQ detail'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(string $rfq): RfqResource
    {
        $model = $this->detailHandler->handle(new GetRfqDetailQuery($rfq));
        $this->authorize('view', $model);

        return new RfqResource($model);
    }

    /**
     * Update a draft request for quotation.
     */
    #[OA\Put(
        path: '/api/v1/procurement/rfqs/{rfq}',
        operationId: 'procurementRfqsUpdate',
        summary: 'Update draft request for quotation',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'RFQ updated'),
            new OA\Response(response: 422, description: 'Invalid state or validation failed'),
        ]
    )]
    public function update(UpdateRfqRequest $request, string $rfq): RfqResource
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('update', $model);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $lines = null;

        if (array_key_exists('lines', $data)) {
            $lines = [];
            foreach ((array) $data['lines'] as $line) {
                /** @var array<string, mixed> $line */
                $lines[] = [
                    'source_requisition_line_id' => $line['source_requisition_line_id'] ?? null,
                    'item_id' => $line['item_id'] ?? null,
                    'custom_item_code' => $line['custom_item_code'] ?? null,
                    'internal_sku' => $line['internal_sku'] ?? null,
                    'description' => (string) $line['description'],
                    'technical_specifications' => $line['technical_specifications'] ?? null,
                    'quantity' => $line['quantity'],
                    'uom' => (string) ($line['unit_of_measure'] ?? $line['uom'] ?? 'PCS'),
                    'target_unit_price' => $line['target_unit_price'] ?? null,
                    'requested_delivery_date' => $line['requested_delivery_date'] ?? null,
                ];
            }
        }

        $updated = $this->updateHandler->handle(new UpdateRfqCommand(
            rfqId: (string) $model->id,
            title: $data['title'] ?? null,
            description: $data['description'] ?? null,
            currencyCode: $data['currency_code'] ?? $data['currency'] ?? null,
            incoterms: $data['incoterms'] ?? null,
            submissionDeadline: $data['submission_deadline'] ?? null,
            expectedDeliveryDate: $data['expected_delivery_date'] ?? null,
            evaluationWeights: isset($data['evaluation_weights']) && is_array($data['evaluation_weights']) ? $data['evaluation_weights'] : null,
            sealedBidding: isset($data['sealed_bidding']) ? (bool) $data['sealed_bidding'] : null,
            costCenterId: $data['cost_center_id'] ?? null,
            notes: $data['notes'] ?? null,
            supplierIds: isset($data['supplier_ids']) ? array_values(array_map('strval', (array) $data['supplier_ids'])) : null,
            lines: $lines,
        ));

        return new RfqResource($updated);
    }

    /**
     * Publish a draft RFQ, generate magic-link tokens, and dispatch invitation emails.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/publish',
        operationId: 'procurementRfqsPublish',
        summary: 'Publish RFQ and dispatch supplier invitations',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'RFQ published'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function publish(Request $request, string $rfq): RfqResource
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('publish', $model);

        $published = $this->publishHandler->handle(new PublishAndDispatchRfqCommand(
            rfqId: (string) $model->id,
            actorUserId: $this->actorId($request),
        ));

        return new RfqResource($published);
    }

    /**
     * Legacy issue endpoint preserved for backward compatibility.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/issue',
        operationId: 'procurementRfqsIssue',
        summary: 'Issue request for quotation (legacy)',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Request issued'),
        ]
    )]
    public function issue(string $rfq): RfqResource
    {
        $legacyModel = Rfq::query()->findOrFail($rfq);
        $this->authorize('publish', $legacyModel);
        $this->legacyRfqService->issue($legacyModel);

        return new RfqResource($this->detailHandler->handle(new GetRfqDetailQuery($rfq)));
    }

    /**
     * Extend the submission deadline of an RFQ and notify invited suppliers.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/extend-deadline',
        operationId: 'procurementRfqsExtendDeadline',
        summary: 'Extend RFQ submission deadline',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Deadline extended'),
            new OA\Response(response: 422, description: 'Invalid deadline'),
        ]
    )]
    public function extendDeadline(ExtendRfqDeadlineRequest $request, string $rfq): RfqResource
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('extendDeadline', $model);

        $updated = $this->extendDeadlineHandler->handle(new ExtendRfqDeadlineCommand(
            rfqId: (string) $model->id,
            newSubmissionDeadline: (string) $request->validated('submission_deadline'),
            actorUserId: $this->actorId($request),
        ));

        return new RfqResource($updated);
    }

    /**
     * Side-by-side bid comparison matrix with normalized weighted scoring and sealed-bidding compliance.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs/{rfq}/comparison-matrix',
        operationId: 'procurementRfqsComparisonMatrix',
        summary: 'Get side-by-side bid comparison matrix',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Comparison matrix'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function comparisonMatrix(string $rfq): BidComparisonMatrixResource
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('view', $model);

        return new BidComparisonMatrixResource(
            $this->matrixHandler->handle(new GetBidComparisonMatrixQuery((string) $model->id))
        );
    }

    /**
     * Legacy comparison endpoint preserved for backward compatibility.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs/{rfq}/comparison',
        operationId: 'procurementRfqsComparison',
        summary: 'Compare supplier quotes (legacy)',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'Comparison'),
        ]
    )]
    public function comparison(string $rfq): JsonResponse
    {
        $legacyModel = Rfq::query()->findOrFail($rfq);
        $this->authorize('view', $legacyModel);

        return response()->json(['data' => $this->legacyRfqService->compare($legacyModel)]);
    }

    /**
     * Legacy internal quote recording endpoint preserved for backward compatibility.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/quotes',
        operationId: 'procurementRfqsStoreQuote',
        summary: 'Record supplier quote (legacy)',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'Quote recorded'),
        ]
    )]
    public function storeQuote(StoreSupplierQuoteRequest $request, string $rfq): JsonResponse
    {
        $legacyModel = Rfq::query()->findOrFail($rfq);
        $this->authorize('update', $legacyModel);

        /** @var array{supplier_id: string, quote_reference?: ?string, quote_date: string, valid_until?: ?string, lead_time_days?: ?int, notes?: ?string, lines: list<array{rfq_line_id: string, unit_price: string, quantity?: string|null, tax_rate?: string|null, lead_time_days?: ?int}>} $data */
        $data = $request->validated();

        return (new SupplierQuoteResource($this->legacyRfqService->recordQuote($legacyModel, $data)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Execute Full Award or Split Award, generating Mod 046 Purchase Order(s) and sending notifications.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/award',
        operationId: 'procurementRfqsAward',
        summary: 'Award RFQ (Full or Split Award)',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'Award completed and purchase orders generated'),
            new OA\Response(response: 422, description: 'Validation failed or sealed bidding active'),
        ]
    )]
    public function award(AwardRfqRequest $request, string $rfq): JsonResponse
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('award', $model);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $isLegacyShape = isset($data['supplier_quote_id']) && ! isset($data['award_mode']);
        $mode = AwardMode::tryFrom((string) ($data['award_mode'] ?? 'full')) ?? AwardMode::Full;

        $lineAwards = [];
        foreach ((array) ($data['line_awards'] ?? []) as $item) {
            /** @var array{rfq_line_id: string, supplier_bid_line_id: string} $item */
            $lineAwards[] = [
                'rfq_line_id' => (string) $item['rfq_line_id'],
                'supplier_bid_line_id' => (string) $item['supplier_bid_line_id'],
            ];
        }

        $result = $this->awardHandler->handle(new AwardRfqCommand(
            rfqId: (string) $model->id,
            actorUserId: $this->actorId($request),
            awardMode: $mode,
            supplierId: isset($data['supplier_id']) ? (string) $data['supplier_id'] : null,
            bidId: isset($data['bid_id']) ? (string) $data['bid_id'] : (isset($data['supplier_quote_id']) ? (string) $data['supplier_quote_id'] : null),
            lineAwards: $lineAwards,
            expectedDeliveryDate: isset($data['expected_delivery_date']) ? (string) $data['expected_delivery_date'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        ));

        if ($isLegacyShape) {
            return response()->json(new PurchaseOrderResource($result->purchaseOrders[0]->load('lines')), 201);
        }

        return response()->json([
            'data' => [
                'rfq' => new RfqResource($result->rfq),
                'purchase_orders' => PurchaseOrderResource::collection(
                    array_map(static fn ($po) => $po->load('lines'), $result->purchaseOrders)
                ),
            ],
        ], 201);
    }

    /**
     * Cancel an RFQ, release locked Mod 044 PR lines, and notify invited suppliers.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{rfq}/cancel',
        operationId: 'procurementRfqsCancel',
        summary: 'Cancel request for quotation',
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 200, description: 'RFQ cancelled'),
            new OA\Response(response: 422, description: 'Invalid state'),
        ]
    )]
    public function cancel(CancelRfqRequest $request, string $rfq): RfqResource
    {
        $model = RequestForQuotation::query()->findOrFail($rfq);
        $this->authorize('cancel', $model);

        $cancelled = $this->cancelHandler->handle(new CancelRfqCommand(
            rfqId: (string) $model->id,
            actorUserId: $this->actorId($request),
            reason: $request->validated('reason'),
        ));

        return new RfqResource($cancelled);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
