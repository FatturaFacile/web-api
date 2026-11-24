<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Modules\AccountsPayable\Enums\OpenItemStatus;
use Modules\AccountsPayable\Http\Controllers\Concerns\ResolvesAccountsPayableContext;
use Modules\AccountsPayable\Http\Requests\EmptyActionRequest;
use Modules\AccountsPayable\Http\Requests\HoldOpenItemRequest;
use Modules\AccountsPayable\Http\Requests\PayablesAgingRequest;
use Modules\AccountsPayable\Http\Requests\PayablesBatchCriteriaRequest;
use Modules\AccountsPayable\Http\Requests\PayablesBatchDecisionRequest;
use Modules\AccountsPayable\Http\Requests\PayablesBatchIndexRequest;
use Modules\AccountsPayable\Http\Requests\PayablesBlockRequest;
use Modules\AccountsPayable\Http\Requests\PayablesItemsIndexRequest;
use Modules\AccountsPayable\Http\Resources\PayableBatchResource;
use Modules\AccountsPayable\Http\Resources\PayableItemResource;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Services\AccountingPayablesPresenter;
use Modules\AccountsPayable\Services\OpenItemService;
use Modules\AccountsPayable\Services\PayablesAgingExporter;
use Modules\AccountsPayable\Services\PayablesAgingService;
use Modules\AccountsPayable\Services\PaymentRunService;
use OpenApi\Attributes as OA;

/**
 * Accounts payable in the contract of the web client, `/api/v1/accounting/payables`: the items (supplier open items), the batches
 * (payment runs) and the aging. It is a thin layer over the services of the module, shared with `/api/v1/accounts-payable`: every
 * rule (holds, Skonto, netting, approval, posting) lives there. The responses carry the `{data}` envelope and the Laravel
 * pagination of the client's models.
 */
final class AccountingPayablesController extends Controller
{
    use ResolvesAccountsPayableContext;

    public function __construct(
        private readonly OpenItemService $items,
        private readonly PaymentRunService $runs,
        private readonly PayablesAgingService $aging,
        private readonly PayablesAgingExporter $exporter,
        private readonly AccountingPayablesPresenter $presenter,
    ) {}

    #[OA\Get(path: '/api/v1/accounting/payables/items', operationId: 'listAccountingPayableItems', summary: 'Supplier open items (client contract)', description: 'Open items with their Skonto opportunity. Filters: supplier_id, status (open, in_batch, authorized, paid, disputed, blocked), document_type (xml_invoice, qr_bill, manual_bill, credit_note), currency, due_from, due_to, skonto_only, open_only, search, per_page, page.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'A page of items')])]
    public function items(PayablesItemsIndexRequest $request): AnonymousResourceCollection
    {
        return PayableItemResource::collection($this->items->paginate($this->tenantId(), $request->filters(), CarbonImmutable::today(), (int) $request->input('per_page', 25), (int) $request->input('page', 1)));
    }

    #[OA\Get(path: '/api/v1/accounting/payables/items/summary', operationId: 'getAccountingPayableItemsSummary', summary: 'Totals of the supplier ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'Open, overdue, Skonto savings and held items')])]
    public function summary(PayablesAgingRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->items->summary($this->tenantId(), $request->currency(), CarbonImmutable::today())]);
    }

    #[OA\Get(path: '/api/v1/accounting/payables/items/{id}', operationId: 'showAccountingPayableItem', summary: 'One supplier open item with its dispute and block history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The item'), new OA\Response(response: 404, description: 'Unknown item')])]
    public function item(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->item($this->items->find($this->tenantId(), $id), true)]);
    }

    #[OA\Post(path: '/api/v1/accounting/payables/items/{id}/block', operationId: 'blockAccountingPayableItem', summary: 'Put an item in dispute or under administrative block', description: 'kind dispute or block, with the reason. The item leaves every batch until it is released.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The held item'), new OA\Response(response: 409, description: 'Already held or settled')])]
    public function block(PayablesBlockRequest $request, string $id): JsonResponse
    {
        $hold = $request->validated('kind') === 'dispute' ? OpenItemStatus::Disputed : OpenItemStatus::Blocked;
        $item = $this->items->hold($this->tenantId(), $id, $hold, (string) $request->validated('reason'), $this->userId($request));

        return response()->json(['data' => $this->presenter->item($item, true)]);
    }

    #[OA\Post(path: '/api/v1/accounting/payables/items/{id}/release', operationId: 'releaseAccountingPayableItem', summary: 'Release a disputed or blocked item', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The released item')])]
    public function release(HoldOpenItemRequest $request, string $id): JsonResponse
    {
        $item = $this->items->release($this->tenantId(), $id, (string) $request->validated('reason'), $this->userId($request));

        return response()->json(['data' => $this->presenter->item($item, true)]);
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches/preview', operationId: 'previewAccountingPayableBatch', summary: 'Preview a payment batch', description: 'The candidates, the credit notes, the items left out and the batch the cutoff would produce, with the set-off and the transfers by IBAN. Nothing is saved.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The preview')])]
    public function preview(PayablesBatchCriteriaRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $criteria = $request->criteria();
        $proposal = $this->runs->proposal($tenantId, $criteria);
        $today = CarbonImmutable::today();
        $excluded = array_map(fn (array $row): array => ['item_id' => $row['open_item_id'], 'reason' => $this->presenter->excludedReason($row['reason'])], $proposal['plan']['excluded']);

        foreach ($proposal['in_run'] as $item) {
            $excluded[] = ['item_id' => (string) $item->id, 'reason' => 'in_batch'];
        }

        return response()->json(['data' => [
            'cutoff_date' => $criteria['cutoff_date'],
            'currency' => $criteria['currency'],
            'candidates' => $this->presented($tenantId, $proposal['candidates']->pluck('id')->all(), $today),
            'credit_notes' => $this->presented($tenantId, $proposal['credits']->pluck('id')->all(), $today),
            'excluded' => $excluded,
            'batch' => $this->presenter->plannedBatch($proposal['plan'], $criteria['cutoff_date'], $criteria['currency'], $criteria['include_skonto']),
        ]]);
    }

    #[OA\Get(path: '/api/v1/accounting/payables/batches', operationId: 'listAccountingPayableBatches', summary: 'Payment batches', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'A page of batches')])]
    public function batches(PayablesBatchIndexRequest $request): AnonymousResourceCollection
    {
        return PayableBatchResource::collection($this->runs->paginate($this->tenantId(), ['status' => $request->runStatus()], (int) $request->input('per_page', 25)));
    }

    #[OA\Get(path: '/api/v1/accounting/payables/batches/{id}', operationId: 'showAccountingPayableBatch', summary: 'A payment batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The batch'), new OA\Response(response: 404, description: 'Unknown batch')])]
    public function batch(string $id): JsonResponse
    {
        return $this->batchResponse($this->runs->find($this->tenantId(), $id));
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches', operationId: 'createAccountingPayableBatch', summary: 'Prepare a payment batch', description: 'Creates a draft with the ticked items and credit notes and reserves them.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 201, description: 'The draft batch'), new OA\Response(response: 422, description: 'No item matches')])]
    public function createBatch(PayablesBatchCriteriaRequest $request): JsonResponse
    {
        return $this->batchResponse($this->runs->create($this->tenantId(), $request->criteria(), $this->userId($request)), 201);
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches/{id}/submit', operationId: 'submitAccountingPayableBatch', summary: 'Send a batch to the CFO', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The batch awaiting approval')])]
    public function submit(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->batchResponse($this->runs->submit($this->tenantId(), $id, $this->userId($request)));
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches/{id}/approve', operationId: 'approveAccountingPayableBatch', summary: 'CFO approval of a batch', description: 'The approver must be neither the author nor the submitter.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The approved batch'), new OA\Response(response: 403, description: 'Segregation of duties')])]
    public function approve(PayablesBatchDecisionRequest $request, string $id): JsonResponse
    {
        return $this->batchResponse($this->runs->approve($this->tenantId(), $id, (string) $this->userId($request)));
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches/{id}/reject', operationId: 'rejectAccountingPayableBatch', summary: 'CFO rejection of a batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The rejected batch')])]
    public function reject(PayablesBatchDecisionRequest $request, string $id): JsonResponse
    {
        return $this->batchResponse($this->runs->reject($this->tenantId(), $id, (string) $this->userId($request), (string) $request->validated('reason')));
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches/{id}/execute', operationId: 'executeAccountingPayableBatch', summary: 'Execute an approved batch', description: 'The items become paid and the Skonto entry is posted.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The executed batch')])]
    public function execute(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->batchResponse($this->runs->execute($this->tenantId(), $id, $this->userId($request)));
    }

    #[OA\Post(path: '/api/v1/accounting/payables/batches/{id}/cancel', operationId: 'cancelAccountingPayableBatch', summary: 'Cancel a batch that was not executed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The cancelled batch')])]
    public function cancel(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->batchResponse($this->runs->cancel($this->tenantId(), $id, $this->userId($request)));
    }

    #[OA\Get(path: '/api/v1/accounting/payables/aging', operationId: 'getAccountingPayablesAging', summary: 'Payables aging (client contract)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The aging by supplier and bucket')])]
    public function agingReport(PayablesAgingRequest $request): JsonResponse
    {
        $report = $this->aging->report($this->tenantId(), $request->asOf(), $request->supplierId(), $request->currency(), false);

        return response()->json(['data' => $this->presenter->aging($report)]);
    }

    #[OA\Get(path: '/api/v1/accounting/payables/aging/export/{format}', operationId: 'exportAccountingPayablesAging', summary: 'Payables aging as an Excel workbook (xlsx) or a PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], parameters: [new OA\Parameter(name: 'format', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf']))], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function exportAging(PayablesAgingRequest $request, string $format): Response
    {
        $tenantId = $this->tenantId();
        $report = $this->aging->report($tenantId, $request->asOf(), $request->supplierId(), $request->currency());
        $locale = (string) ($request->input('locale') ?: app()->getLocale());

        return $format === 'pdf'
            ? $this->download($this->exporter->pdf($report, $locale, $tenantId), 'payables-aging-'.$report['as_of'].'.pdf', 'application/pdf')
            : $this->download($this->exporter->xlsx($report, $locale, $tenantId), 'payables-aging-'.$report['as_of'].'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function batchResponse(ApPaymentRun $run, int $status = 200): JsonResponse
    {
        return (new PayableBatchResource($run))->response()->setStatusCode($status);
    }

    /**
     * The items with their Skonto figures, in the order given.
     *
     * @param  list<string>|Collection<int, string>  $ids
     * @return list<array<string, mixed>>
     */
    private function presented(string $tenantId, array|Collection $ids, CarbonImmutable $today): array
    {
        $ids = array_values(collect($ids)->map(static fn (mixed $id): string => (string) $id)->all());
        $items = ApOpenItem::query()->with(['supplier', 'passiveInvoice'])->where('tenant_id', $tenantId)->whereIn('id', $ids)->get();
        $this->items->decorate($items, $today);
        $byId = $items->keyBy('id');

        $ordered = array_values(array_filter($ids, static fn (string $id): bool => $byId->has($id)));

        return array_map(fn (string $id): array => $this->presenter->item($byId->get($id)), $ordered);
    }
}
