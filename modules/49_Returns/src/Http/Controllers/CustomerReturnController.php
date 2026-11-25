<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Returns\Http\Controllers\Concerns\InteractsWithReturns;
use Modules\Returns\Http\Requests\CreditNoteFiscalCheckRequest;
use Modules\Returns\Http\Requests\CustomerReturnReceiveRequest;
use Modules\Returns\Http\Requests\CustomerReturnStoreRequest;
use Modules\Returns\Http\Requests\EligibilityCheckRequest;
use Modules\Returns\Http\Requests\IssueReturnCreditNoteRequest;
use Modules\Returns\Http\Resources\CustomerReturnResource;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Policies\ReturnsPolicy;
use Modules\Returns\Services\CreditNoteFiscalControlService;
use Modules\Returns\Services\CustomerReturnService;
use Modules\Returns\Services\ReturnEligibilityService;
use OpenApi\Attributes as OA;

/**
 * Mod 232 Customer Returns (Reverse Logistics): eligibility check, self-service & backoffice RMA,
 * RMA barcode/label, receipt, and Art. 26 DPR 633/72 / Art. 41 LIVA Credit Note (TD04) control.
 */
final class CustomerReturnController extends Controller
{
    use InteractsWithReturns;

    public function __construct(
        private readonly CustomerReturnService $returns,
        private readonly ReturnEligibilityService $eligibility,
        private readonly CreditNoteFiscalControlService $creditNotes,
    ) {}

    #[OA\Post(path: '/api/v1/returns/eligibility-check', operationId: 'checkReturnEligibility', summary: 'Evaluate return & warranty eligibility and restocking fee', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Eligibility evaluation'),
    ])]
    public function eligibilityCheck(EligibilityCheckRequest $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerReturn::class);

        return response()->json([
            'data' => $this->eligibility->evaluate($this->tenantId(), $request->validated()),
        ]);
    }

    #[OA\Get(path: '/api/v1/returns/customer-returns', operationId: 'listCustomerReturnsMod232', summary: 'List Mod 232 customer returns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Customer returns'),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomerReturn::class);
        $status = $request->query('status');
        $channel = $request->query('channel');
        $search = $request->query('search');

        return CustomerReturnResource::collection(
            CustomerReturn::query()
                ->with(['lines', 'contact', 'invoice', 'deliveryNote'])
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($status) && $status !== '', static fn ($q) => $q->where('status', $status))
                ->when(is_string($channel) && $channel !== '', static fn ($q) => $q->where('channel', $channel))
                ->when(is_string($search) && $search !== '', static fn ($q) => $q->where('rma_number', 'like', '%'.$search.'%'))
                ->orderByDesc('requested_on')
                ->orderByDesc('rma_number')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/returns/customer-returns', operationId: 'storeCustomerReturnMod232', summary: 'Create a Mod 232 customer return (portal or backoffice)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Customer return created'),
    ])]
    public function store(CustomerReturnStoreRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerReturn::class);
        $record = $this->returns->create(
            $this->tenantId(),
            $request->validated(),
            $this->userId(),
            app(ReturnsPolicy::class)->mayApprove($request->user())
        );

        return (new CustomerReturnResource($record))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/returns/customer-returns/{id}', operationId: 'showCustomerReturnMod232', summary: 'Show a Mod 232 customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Customer return'),
    ])]
    public function show(string $id): CustomerReturnResource
    {
        $record = CustomerReturn::query()->with(['lines', 'contact', 'invoice', 'deliveryNote'])->findOrFail($id);
        $this->authorize('view', $record);

        return new CustomerReturnResource($record);
    }

    #[OA\Post(path: '/api/v1/returns/customer-returns/{id}/approve', operationId: 'approveCustomerReturnMod232', summary: 'Approve a Mod 232 customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Approved'),
    ])]
    public function approve(Request $request, string $id): CustomerReturnResource
    {
        $record = CustomerReturn::query()->findOrFail($id);
        $this->authorize('approve', $record);

        $warehouseId = $request->input('warehouse_id');

        return new CustomerReturnResource(
            $this->returns->approve($record, $this->userId(), is_string($warehouseId) ? $warehouseId : null)
        );
    }

    #[OA\Post(path: '/api/v1/returns/customer-returns/{id}/receive', operationId: 'receiveCustomerReturnMod232', summary: 'Receive goods for a Mod 232 customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Received'),
    ])]
    public function receive(CustomerReturnReceiveRequest $request, string $id): CustomerReturnResource
    {
        $record = CustomerReturn::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new CustomerReturnResource($this->returns->receive($record, $request->validated()));
    }

    #[OA\Get(path: '/api/v1/returns/customer-returns/{id}/label', operationId: 'getCustomerReturnLabelMod232', summary: 'Get RMA barcode and return shipping label payload', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Shipping label payload'),
    ])]
    public function label(string $id): JsonResponse
    {
        $record = CustomerReturn::query()->findOrFail($id);
        $this->authorize('view', $record);

        return response()->json([
            'data' => $this->eligibility->buildShippingLabel(
                $record->rma_number,
                $record->rma_barcode,
                $record->carrier,
                $record->return_tracking_number
            ),
        ]);
    }

    #[OA\Post(path: '/api/v1/returns/credit-note-check', operationId: 'checkReturnCreditNoteFiscalRules', summary: 'Check Art. 26 DPR 633/72 (1-year N2.2) and Art. 41 LIVA rules before issuing a TD04 Credit Note', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 200, description: 'Fiscal check result'),
    ])]
    public function creditNoteCheck(CreditNoteFiscalCheckRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $record = CustomerReturn::query()->findOrFail($validated['customer_return_id']);
        $this->authorize('view', $record);

        return response()->json([
            'data' => $this->creditNotes->check($this->tenantId(), $record, $validated['credit_note_date'] ?? null),
        ]);
    }

    #[OA\Post(path: '/api/v1/returns/credit-notes', operationId: 'issueReturnCreditNote', summary: 'Issue a TD04 / Art. 41 LIVA Credit Note linked to a customer return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Returns'], responses: [
        new OA\Response(response: 201, description: 'Credit note issued'),
    ])]
    public function issueCreditNote(IssueReturnCreditNoteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $record = CustomerReturn::query()->findOrFail($validated['customer_return_id']);
        $this->authorize('approve', $record);

        $creditNote = $this->creditNotes->issueCreditNote($this->tenantId(), $record, $validated, $this->userId());

        return response()->json([
            'data' => [
                'credit_note_id' => (string) $creditNote->id,
                'invoice_number' => (string) $creditNote->invoice_number,
                'document_type' => $creditNote->document_type,
                'vat_nature' => $creditNote->vat_nature,
                'total_net' => (string) $creditNote->total_net,
                'total_tax' => (string) $creditNote->total_tax,
                'total_gross' => (string) $creditNote->total_gross,
                'customer_return_id' => $record->id,
            ],
        ], Response::HTTP_CREATED);
    }
}
