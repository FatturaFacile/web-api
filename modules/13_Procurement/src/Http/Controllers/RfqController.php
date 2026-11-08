<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\AwardRfqRequest;
use Modules\Procurement\Http\Requests\StoreRfqRequest;
use Modules\Procurement\Http\Requests\StoreSupplierQuoteRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\RfqResource;
use Modules\Procurement\Http\Resources\SupplierQuoteResource;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Services\RfqService;
use OpenApi\Attributes as OA;

/**
 * Requests for quotation: sourcing of a requisition (or of a list of items) from several suppliers, recording and
 * comparing their quotes, and awarding the winning quote as a purchase order.
 */
#[OA\Tag(name: 'Procurement RFQ', description: 'Requests for quotation, supplier quotes, comparison and award')]
final class RfqController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly RfqService $rfqs) {}

    /**
     * Paginated requests for quotation.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs',
        operationId: 'procurementRfqsIndex',
        description: 'Requests for quotation of the company, newest first, optionally by status.',
        summary: 'List requests for quotation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcStatusFilter'), new OA\Parameter(ref: '#/components/parameters/ProcPage'), new OA\Parameter(ref: '#/components/parameters/ProcPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementRfqResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Rfq::query()->with('invitedSuppliers')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return RfqResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * Creates a request for quotation.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs',
        operationId: 'procurementRfqsStore',
        description: 'Creates a draft request for quotation for several suppliers. From an approved requisition its lines are copied and the requisition goes into sourcing; otherwise give the lines.',
        summary: 'Create request for quotation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title', 'response_deadline', 'supplier_ids'], properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'response_deadline', type: 'string', format: 'date'),
            new OA\Property(property: 'purchase_requisition_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
            new OA\Property(property: 'supplier_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'quantity', type: 'string'),
                new OA\Property(property: 'uom', type: 'string'),
                new OA\Property(property: 'internal_sku', type: 'string', nullable: true),
                new OA\Property(property: 'required_by_date', type: 'string', format: 'date', nullable: true),
            ])),
        ])),
        tags: ['Procurement RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'Request created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementRfqResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function store(StoreRfqRequest $request): JsonResponse
    {
        /** @var array{title: string, response_deadline: string, currency?: ?string, purchase_requisition_id?: ?string, cost_center_id?: ?string, notes?: ?string, supplier_ids: list<string>, lines?: list<array{description: string, quantity: string, uom?: ?string, internal_sku?: ?string, required_by_date?: ?string}>} $data */
        $data = $request->validated();

        return (new RfqResource($this->rfqs->create($this->tenantId(), $data, $this->actorId($request))))->response()->setStatusCode(201);
    }

    /**
     * One request with lines, suppliers and quotes.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs/{id}',
        operationId: 'procurementRfqsShow',
        description: 'A request for quotation with its lines, invited suppliers and quotes.',
        summary: 'Show request for quotation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementRfqResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function show(string $id): RfqResource
    {
        return new RfqResource(Rfq::query()->with(['lines', 'invitedSuppliers', 'quotes.lines'])->findOrFail($id));
    }

    /**
     * Issues the request to the suppliers.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{id}/issue',
        operationId: 'procurementRfqsIssue',
        description: 'Issues a draft request to the invited suppliers; quotes can be recorded afterwards.',
        summary: 'Issue request for quotation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Request issued', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementRfqResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function issue(string $id): RfqResource
    {
        return new RfqResource($this->rfqs->issue(Rfq::query()->findOrFail($id)));
    }

    /**
     * Records the quote of an invited supplier.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{id}/quotes',
        operationId: 'procurementRfqsStoreQuote',
        description: 'Records the quote of an invited supplier received by the response deadline, one per supplier, with the price of each line.',
        summary: 'Record supplier quote',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['supplier_id', 'quote_date', 'lines'], properties: [
            new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'quote_reference', type: 'string', nullable: true),
            new OA\Property(property: 'quote_date', type: 'string', format: 'date'),
            new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'rfq_line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'unit_price', type: 'string'),
                new OA\Property(property: 'quantity', type: 'string', nullable: true),
                new OA\Property(property: 'tax_rate', type: 'string', nullable: true),
            ])),
        ])),
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Quote recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementSupplierQuoteResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function storeQuote(StoreSupplierQuoteRequest $request, string $id): JsonResponse
    {
        /** @var array{supplier_id: string, quote_reference?: ?string, quote_date: string, valid_until?: ?string, lead_time_days?: ?int, notes?: ?string, lines: list<array{rfq_line_id: string, unit_price: string, quantity?: string|null, tax_rate?: string|null, lead_time_days?: ?int}>} $data */
        $data = $request->validated();

        return (new SupplierQuoteResource($this->rfqs->recordQuote(Rfq::query()->findOrFail($id), $data)))->response()->setStatusCode(201);
    }

    /**
     * Side-by-side comparison of the quotes.
     */
    #[OA\Get(
        path: '/api/v1/procurement/rfqs/{id}/comparison',
        operationId: 'procurementRfqsComparison',
        description: 'Per line the offers cheapest first; per quote total, coverage, lead time and supplier rating; the recommended quote is the cheapest covering every line.',
        summary: 'Compare supplier quotes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'rfq_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'quotes', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'recommended_quote_id', type: 'string', format: 'uuid', nullable: true),
            ], type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function comparison(string $id): JsonResponse
    {
        return response()->json(['data' => $this->rfqs->compare(Rfq::query()->findOrFail($id))]);
    }

    /**
     * Awards the request to a quote.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{id}/award',
        operationId: 'procurementRfqsAward',
        description: 'Creates a draft purchase order at the prices of the winning quote, rejects the others and marks the requisition ordered. The order then follows its own approval.',
        summary: 'Award request for quotation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['supplier_quote_id'], properties: [
            new OA\Property(property: 'supplier_quote_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'expected_delivery_date', type: 'string', format: 'date', nullable: true),
        ])),
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Purchase order created', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function award(AwardRfqRequest $request, string $id): JsonResponse
    {
        $order = $this->rfqs->award(Rfq::query()->findOrFail($id), (string) $request->validated('supplier_quote_id'), $this->actorId($request), $request->validated('expected_delivery_date'));

        return response()->json(new PurchaseOrderResource($order->load('lines')), 201);
    }

    /**
     * Cancels an open request.
     */
    #[OA\Post(
        path: '/api/v1/procurement/rfqs/{id}/cancel',
        operationId: 'procurementRfqsCancel',
        description: 'Cancels a draft or issued request; its requisition goes back to approved.',
        summary: 'Cancel request for quotation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement RFQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Request cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementRfqResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function cancel(string $id): RfqResource
    {
        return new RfqResource($this->rfqs->cancel(Rfq::query()->findOrFail($id)));
    }
}
