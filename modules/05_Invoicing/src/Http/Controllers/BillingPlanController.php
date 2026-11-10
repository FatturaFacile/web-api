<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Invoicing\Http\Requests\InvoiceBillingPlanItemRequest;
use Modules\Invoicing\Http\Requests\RunBillingPlansRequest;
use Modules\Invoicing\Http\Requests\StoreBillingPlanRequest;
use Modules\Invoicing\Http\Resources\BillingPlanResource;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\BillingSchedule;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Services\OrderBillingService;
use OpenApi\Attributes as OA;

/**
 * Billing plans (milestone billing) of sales orders: each item is invoiced on its own, on demand or by the run of the
 * items due. An order billed by a plan is not billed by quantities.
 */
final class BillingPlanController extends Controller
{
    public function __construct(private readonly OrderBillingService $billing) {}

    /**
     * Lists the billing plans of the active company, optionally of one order or in one status.
     *
     * @response AnonymousResourceCollection<LengthAwarePaginator<BillingPlanResource>>
     */
    #[OA\Get(
        path: '/api/v1/invoicing/billing-plans',
        operationId: 'listInvoicingBillingPlans',
        description: 'Lists the billing plans of the active company with their items, newest first, optionally of one sales order or in one status. The list is paginated (per_page up to 100, default 25): the body is the Laravel paginator document with data, links and meta.',
        summary: 'List billing plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Billing'],
        parameters: [
            new OA\Parameter(name: 'sales_order_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Billing plans', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingBillingPlanResource')),
                new OA\Property(property: 'links', ref: '#/components/schemas/InvoicingPaginatorLinks'),
                new OA\Property(property: 'meta', ref: '#/components/schemas/InvoicingPaginatorMeta'),
            ], type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $plans = BillingSchedule::query()
            ->with('items')
            ->when($request->filled('sales_order_id'), static fn ($query) => $query->where('sales_order_id', (string) $request->query('sales_order_id')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json(BillingPlanResource::collection($plans)->response()->getData(true));
    }

    /**
     * Creates the billing plan of a sales order.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/billing-plans',
        operationId: 'createInvoicingBillingPlan',
        description: 'Creates the billing plan of a sales order not yet invoiced: each item is a percentage of the order net total or an amount, with a target date. The items together cannot exceed the order net total, and an order has at most one active plan.',
        summary: 'Create billing plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['sales_order_id', 'items'], properties: [
            new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(required: ['name', 'target_date'], properties: [
                new OA\Property(property: 'name', type: 'string', maxLength: 150),
                new OA\Property(property: 'percentage', type: 'number', nullable: true),
                new OA\Property(property: 'amount', type: 'number', nullable: true),
                new OA\Property(property: 'target_date', type: 'string', format: 'date'),
                new OA\Property(property: 'milestone_id', type: 'string', format: 'uuid', nullable: true),
            ], type: 'object'), minItems: 1),
        ], type: 'object')),
        tags: ['Invoicing Billing'],
        responses: [
            new OA\Response(response: 201, description: 'Billing plan created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingBillingPlanResource')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function store(StoreBillingPlanRequest $request): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail((string) $request->validated('sales_order_id'));
        $plan = $this->billing->createPlan($order, array_values($request->validated('items')), (string) $request->user()?->id);

        return response()->json(new BillingPlanResource($plan), 201);
    }

    /**
     * Returns one billing plan of the active company.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/billing-plans/{id}',
        operationId: 'getInvoicingBillingPlan',
        description: 'Returns a billing plan of the active company with its items and the invoices that billed them.',
        summary: 'Get billing plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/InvoicingBillingPlanId')],
        responses: [
            new OA\Response(response: 200, description: 'Billing plan', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingBillingPlanResource')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 404, ref: '#/components/responses/InvoicingBillingNotFound'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return response()->json(new BillingPlanResource(BillingSchedule::query()->with('items')->findOrFail($id)));
    }

    /**
     * Issues the invoice of one item of a billing plan.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/billing-plans/{id}/items/{itemId}/invoice',
        operationId: 'invoiceInvoicingBillingPlanItem',
        description: 'Issues the invoice of a pending item of an active billing plan: its amount split over the VAT rates of the order in proportion to their net. The plan updates its billed and remaining amounts, and the order its billing status.',
        summary: 'Invoice billing plan item',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingBillingOverrides')),
        tags: ['Invoicing Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/InvoicingBillingPlanId'), new OA\Parameter(ref: '#/components/parameters/InvoicingBillingPlanItemId')],
        responses: [
            new OA\Response(response: 201, ref: '#/components/responses/InvoicingBillingIssuedInvoice'),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/InvoicingBillingNotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function invoiceItem(string $id, string $itemId, InvoiceBillingPlanItemRequest $request): JsonResponse
    {
        $plan = BillingSchedule::query()->findOrFail($id);
        $invoice = $this->billing->invoicePlanItem($plan, $itemId, $request->validated(), (string) $request->user()?->id);

        return response()->json(new SalesInvoiceResource($invoice->load('items')), 201);
    }

    /**
     * Issues every pending billing plan item due on or before a date.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/billing-plans/run',
        operationId: 'runInvoicingBillingPlans',
        description: 'Issues the invoice of every pending item of the active billing plans of the company whose target date is on or before up_to_date. Each invoice is issued in its own transaction; refused items are reported in errors.',
        summary: 'Run billing plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['up_to_date'], properties: [new OA\Property(property: 'up_to_date', type: 'string', format: 'date')], type: 'object')),
        tags: ['Invoicing Billing'],
        responses: [
            new OA\Response(response: 200, description: 'Run completed', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingBillingRunResult')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function run(RunBillingPlansRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $result = $this->billing->invoiceDuePlanItems((string) $tenantContext->getTenantId(), (string) $request->validated('up_to_date'), (string) $request->user()?->id);

        return response()->json([
            'invoices' => SalesInvoiceResource::collection(collect($result['invoices'])->map(static fn (SalesInvoice $invoice): SalesInvoice => $invoice->load('items'))),
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Cancels a billing plan.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/billing-plans/{id}/cancel',
        operationId: 'cancelInvoicingBillingPlan',
        description: 'Cancels an active billing plan: its pending items are cancelled, the invoiced ones stay (they are corrected with credit notes).',
        summary: 'Cancel billing plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/InvoicingBillingPlanId')],
        responses: [
            new OA\Response(response: 200, description: 'Billing plan cancelled', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingBillingPlanResource')),
            new OA\Response(response: 401, ref: '#/components/responses/InvoicingBillingUnauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/InvoicingBillingForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/InvoicingBillingNotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/InvoicingBillingUnprocessable'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        return response()->json(new BillingPlanResource($this->billing->cancelPlan(BillingSchedule::query()->findOrFail($id))));
    }
}
