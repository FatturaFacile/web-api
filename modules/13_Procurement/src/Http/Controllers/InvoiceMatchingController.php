<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Enums\MatchTrigger;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\InvoiceMatchRunIndexRequest;
use Modules\Procurement\Http\Requests\RunInvoiceMatchBatchRequest;
use Modules\Procurement\Http\Requests\RunInvoiceMatchRequest;
use Modules\Procurement\Http\Resources\InvoiceMatchRunResource;
use Modules\Procurement\Services\GrIrAccountingService;
use Modules\Procurement\Services\InvoiceMatchingQueryService;
use Modules\Procurement\Services\InvoiceMatchingService;
use OpenApi\Attributes as OA;

/**
 * Matching workbench of supplier invoices (Mod 048): the 2-way / 3-way runs with their line-by-line comparison, the
 * blocked discrepancy queue, the dashboard figures and the GR/IR accounting of a run.
 */
#[OA\Tag(name: 'Procurement Invoice Matching', description: '2-way and 3-way matching of supplier invoices with purchase orders and goods receipts, tolerances, discrepancy resolution and GR/IR accounting')]
final class InvoiceMatchingController extends Controller
{
    use ApiResponse;
    use ProcurementControllerHelpers;

    public function __construct(
        private readonly InvoiceMatchingService $matching,
        private readonly InvoiceMatchingQueryService $queries,
        private readonly GrIrAccountingService $grIr,
    ) {}

    #[OA\Get(path: '/api/v1/procurement/invoice-matching/runs', operationId: 'listInvoiceMatchingRuns', summary: 'Matching workbench: current matching run of each invoice', description: 'Filter by status, match type (2_way, 3_way), supplier or search (invoice or order number). queue=blocked lists the blocked discrepancy queue.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'A page of matching runs')])]
    public function index(InvoiceMatchRunIndexRequest $request): JsonResponse
    {
        $page = $this->queries->paginate($request->safe()->only(['status', 'match_type', 'supplier_id', 'search', 'queue']), $this->perPage($request));

        return $this->successResponse(InvoiceMatchRunResource::collection($page->items()), __('invoice_matching.messages.runs_retrieved'), 200, [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Get(path: '/api/v1/procurement/invoice-matching/summary', operationId: 'summarizeInvoiceMatching', summary: 'Matching dashboard figures', description: 'Runs by status, the blocked queue (count and invoiced value) and the automatic matching rate.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The figures')])]
    public function summary(): JsonResponse
    {
        return $this->successResponse($this->queries->summary(), __('invoice_matching.messages.summary_retrieved'));
    }

    #[OA\Get(path: '/api/v1/procurement/invoice-matching/runs/{id}', operationId: 'showInvoiceMatchingRun', summary: 'A matching run with its lines and resolution history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The run'), new OA\Response(response: 404, description: 'Not found')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new InvoiceMatchRunResource($this->queries->find($id)), __('invoice_matching.messages.run_retrieved'));
    }

    #[OA\Get(path: '/api/v1/procurement/invoice-matching/invoices/{id}/history', operationId: 'listInvoiceMatchingHistory', summary: 'All the matching runs of an invoice, newest first', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The runs')])]
    public function history(string $id): JsonResponse
    {
        return $this->successResponse(InvoiceMatchRunResource::collection($this->queries->historyOf($id)), __('invoice_matching.messages.history_retrieved'));
    }

    #[OA\Get(path: '/api/v1/procurement/invoice-matching/runs/{id}/gr-ir', operationId: 'showInvoiceMatchingGrIr', summary: 'GR/IR accrual and reversal journal entries of a run', description: 'The goods receipt accruals (inventory debit, GR/IR credit) of the order, the invoice posting that reverses them, and the open GR/IR balance.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The GR/IR picture')])]
    public function grIr(string $id): JsonResponse
    {
        return $this->successResponse($this->grIr->forRun($this->queries->find($id)), __('invoice_matching.messages.gr_ir_retrieved'));
    }

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/run', operationId: 'runInvoiceMatching', summary: 'Match one supplier invoice', description: 'Finds the purchase order (named, linked, referenced in the XML / eBill / OCR payload, or by the lines), compares line by line and either releases the invoice into the payment schedule or blocks it (blocked_discrepancy).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 201, description: 'The matching run'), new OA\Response(response: 422, description: 'Validation error')])]
    public function run(RunInvoiceMatchRequest $request): JsonResponse
    {
        $run = $this->matching->run(
            (string) $request->validated('passive_invoice_id'),
            $request->validated('purchase_order_id'),
            MatchTrigger::Manual,
            $this->actorId($request),
        );

        return $this->successResponse(new InvoiceMatchRunResource($this->queries->find((string) $run->id)), __('invoice_matching.messages.run_completed'), 201);
    }

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/batch-run', operationId: 'runInvoiceMatchingBatch', summary: 'Match the invoices that were not matched yet', description: 'Credit notes are skipped. Invoices without any resolvable purchase order are skipped unless include_unreferenced is true (they are then blocked).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'Counts of the batch')])]
    public function batch(RunInvoiceMatchBatchRequest $request): JsonResponse
    {
        $summary = $this->matching->runBatch(
            $request->validated('supplier_id'),
            (int) ($request->validated('limit') ?? 100),
            (bool) ($request->validated('include_unreferenced') ?? false),
            $this->actorId($request),
        );

        return $this->successResponse($summary, __('invoice_matching.messages.batch_completed'));
    }
}
