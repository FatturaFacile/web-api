<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\AccountsPayable\Http\Controllers\Concerns\ResolvesAccountsPayableContext;
use Modules\AccountsPayable\Http\Requests\CancelPaymentRunRequest;
use Modules\AccountsPayable\Http\Requests\EmptyActionRequest;
use Modules\AccountsPayable\Http\Requests\PaymentRunCriteriaRequest;
use Modules\AccountsPayable\Http\Requests\PaymentRunIndexRequest;
use Modules\AccountsPayable\Http\Requests\RejectPaymentRunRequest;
use Modules\AccountsPayable\Http\Resources\PaymentRunResource;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Services\PaymentRunService;
use OpenApi\Attributes as OA;

/**
 * Payment runs (distinte di pagamento): proposal, CFO approval and execution of the supplier payments.
 */
final class PaymentRunController extends Controller
{
    use ApiResponse;
    use ResolvesAccountsPayableContext;

    public function __construct(private readonly PaymentRunService $runs) {}

    #[OA\Get(path: '/api/v1/accounts-payable/payment-runs', operationId: 'listApPaymentRuns', summary: 'Payment runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'A page of payment runs')])]
    public function index(PaymentRunIndexRequest $request): JsonResponse
    {
        $page = $this->runs->paginate($this->tenantId(), ['status' => $request->input('status'), 'supplier_id' => $request->input('supplier_id')], (int) $request->input('per_page', 25));

        return $this->successResponse(PaymentRunResource::collection($page->items()), __('accounts_payable.messages.payment_runs_retrieved'), 200, [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/preview', operationId: 'previewApPaymentRun', summary: 'Preview a payment run', description: 'The invoices due by the cutoff date (or whose Skonto deadline falls by it) with the Skonto taken, the credit notes offset and the transfers grouped by creditor IBAN. Disputed and blocked items are listed in excluded. Nothing is saved.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The plan')])]
    public function preview(PaymentRunCriteriaRequest $request): JsonResponse
    {
        return $this->successResponse($this->runs->preview($this->tenantId(), $request->criteria()), __('accounts_payable.messages.payment_run_previewed'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs', operationId: 'createApPaymentRun', summary: 'Prepare a payment run', description: 'Creates a draft and reserves its open items (status in_proposal).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 201, description: 'The draft run'), new OA\Response(response: 422, description: 'No open item matches')])]
    public function store(PaymentRunCriteriaRequest $request): JsonResponse
    {
        $run = $this->runs->create($this->tenantId(), $request->criteria(), $this->userId($request));

        return $this->successResponse($this->present($run), __('accounts_payable.messages.payment_run_created'), 201);
    }

    #[OA\Get(path: '/api/v1/accounts-payable/payment-runs/{id}', operationId: 'showApPaymentRun', summary: 'A payment run with its items, transfers and offsets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The run'), new OA\Response(response: 404, description: 'Unknown run')])]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->find($this->tenantId(), $id)), __('accounts_payable.messages.payment_run_retrieved'));
    }

    #[OA\Delete(path: '/api/v1/accounts-payable/payment-runs/{id}/items/{itemId}', operationId: 'removeApPaymentRunItem', summary: 'Take an invoice out of a draft run', description: 'The credit notes, the Skonto and the transfers are worked out again.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The recalculated run'), new OA\Response(response: 409, description: 'The run is not a draft')])]
    public function removeItem(EmptyActionRequest $request, string $id, string $itemId): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->removeItem($this->tenantId(), $id, $itemId, $this->userId($request))), __('accounts_payable.messages.payment_run_item_removed'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/{id}/submit', operationId: 'submitApPaymentRun', summary: 'Send a draft run to the CFO', description: 'draft -> pending_cfo_approval. A run holding a disputed or blocked item is refused with 409.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The run'), new OA\Response(response: 409, description: 'Not a draft, or an item is held or changed')])]
    public function submit(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->submit($this->tenantId(), $id, $this->userId($request))), __('accounts_payable.messages.payment_run_submitted'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/{id}/recall', operationId: 'recallApPaymentRun', summary: 'Take a run back from the CFO', description: 'pending_cfo_approval -> draft.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The run')])]
    public function recall(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->recall($this->tenantId(), $id)), __('accounts_payable.messages.payment_run_recalled'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/{id}/approve', operationId: 'approveApPaymentRun', summary: 'CFO approval of a payment run', description: 'pending_cfo_approval -> authorized; the items become authorized. The approver must be neither the author nor the submitter (403). Needs the accounts_payable_approval permission.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The authorized run'), new OA\Response(response: 403, description: 'Segregation of duties'), new OA\Response(response: 409, description: 'Not pending, or an item is held or changed')])]
    public function approve(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->approve($this->tenantId(), $id, (string) $this->userId($request))), __('accounts_payable.messages.payment_run_approved'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/{id}/reject', operationId: 'rejectApPaymentRun', summary: 'CFO rejection of a payment run', description: 'pending_cfo_approval -> rejected; the items are free again. Needs the accounts_payable_approval permission.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The rejected run')])]
    public function reject(RejectPaymentRunRequest $request, string $id): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->reject($this->tenantId(), $id, (string) $this->userId($request), (string) $request->validated('reason'))), __('accounts_payable.messages.payment_run_rejected'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/{id}/execute', operationId: 'executeApPaymentRun', summary: 'Execute an authorized payment run', description: 'authorized -> executed. Records the payments, the cash discounts (posted to the cash-discount account) and the credit-note offsets on the supplier invoices; a disputed or blocked item stops the execution with 409.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The executed run'), new OA\Response(response: 409, description: 'Not authorized, or an item is held or changed')])]
    public function execute(EmptyActionRequest $request, string $id): JsonResponse
    {
        return $this->successResponse($this->present($this->runs->execute($this->tenantId(), $id, $this->userId($request))), __('accounts_payable.messages.payment_run_executed'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/payment-runs/{id}/cancel', operationId: 'cancelApPaymentRun', summary: 'Cancel a payment run that was not executed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The cancelled run')])]
    public function cancel(CancelPaymentRunRequest $request, string $id): JsonResponse
    {
        $reason = $request->validated('reason');

        return $this->successResponse($this->present($this->runs->cancel($this->tenantId(), $id, $this->userId($request), is_string($reason) ? $reason : null)), __('accounts_payable.messages.payment_run_cancelled'));
    }

    private function present(ApPaymentRun $run): PaymentRunResource
    {
        return new PaymentRunResource($this->runs->load($run));
    }
}
