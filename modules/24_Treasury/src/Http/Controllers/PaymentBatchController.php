<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\Treasury\Actions\CreatePaymentBatchAction;
use Modules\Treasury\Actions\ExecutePaymentBatchAction;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Http\Requests\StorePaymentBatchRequest;
use Modules\Treasury\Http\Resources\PaymentBatchResource;
use Modules\Treasury\Models\PaymentBatch;
use OpenApi\Attributes as OA;

final class PaymentBatchController extends Controller
{
    public function __construct(
        private readonly PaymentFactoryServiceInterface $paymentFactory,
        private readonly CreatePaymentBatchAction $createAction,
        private readonly ExecutePaymentBatchAction $executeAction
    ) {}

    /**
     * Returns the paginated list of payment batches of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/payment-batches',
        operationId: 'listTreasuryPaymentBatches',
        description: 'Returns the paginated list of payment batches of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List payment batches',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'bank_account_id', in: 'query', required: false, description: 'Filter by bank account', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of payment batches', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryPaymentBatchResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = PaymentBatch::where('tenant_id', $tenantId)->with('lines');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->has('bank_account_id')) {
            $query->where('bank_account_id', $request->query('bank_account_id'));
        }

        $batches = $query->latest('execution_date')->paginate((int) $request->query('per_page', 25));

        return response()->json(PaymentBatchResource::collection($batches)->response()->getData(true));
    }

    /**
     * Creates a new payment batch in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches',
        operationId: 'createTreasuryPaymentBatch',
        description: 'Creates a new payment batch in the active tenant.',
        summary: 'Create payment batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStorePaymentBatchRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Payment batch created', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StorePaymentBatchRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = $tenantContext->getTenantId();
        $lines = $data['lines'];
        unset($data['lines']);

        $batch = $this->createAction->execute($data, $lines, (string) $request->user()?->id);

        return response()->json(new PaymentBatchResource($batch), 201);
    }

    /**
     * Returns a single payment batch of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/payment-batches/{payment_batch}',
        operationId: 'getTreasuryPaymentBatch',
        description: 'Returns a single payment batch of the active tenant.',
        summary: 'Get payment batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'payment_batch', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $batch = PaymentBatch::with(['lines', 'approvals', 'executions'])->findOrFail($id);

        return response()->json(new PaymentBatchResource($batch));
    }

    /**
     * Submits a payment batch to the approval flow.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/submit-approval',
        operationId: 'submitApprovalTreasuryPaymentBatch',
        description: 'Submits a payment batch to the approval flow.',
        summary: 'Submit payment batch for approval',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function submitApproval(Request $request, string $id): JsonResponse
    {
        $batch = $this->paymentFactory->submitForApproval($id, (string) $request->user()?->id);

        return response()->json(new PaymentBatchResource($batch));
    }

    /**
     * Records the approval of the authenticated signatory.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/approve',
        operationId: 'approveTreasuryPaymentBatch',
        description: 'Records the approval of the authenticated user with the address of the request. The batch must be waiting for approval and the approver must not be its creator.',
        summary: 'Approve payment batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The batch cannot be approved by this user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->guarded(fn (): PaymentBatch => $this->paymentFactory->approveBatch($id, (string) $request->user()?->id, $request->ip()));
    }

    /**
     * Records the manual confirmation of the bank outcome.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/confirm',
        operationId: 'confirmTreasuryPaymentBatch',
        description: 'Confirms that the bank executed the lines still pending of an exported batch. The balance is reduced and the payments are posted to the ledger here, on the value date.',
        summary: 'Confirm payment batch manually',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['bank_reference'], properties: [new OA\Property(property: 'bank_reference', type: 'string', maxLength: 100), new OA\Property(property: 'value_date', type: 'string', format: 'date', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'No exported file is waiting for an outcome, or the payment cannot be posted', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function confirm(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'bank_reference' => ['required', 'string', 'max:100'],
            'value_date' => ['nullable', 'date'],
        ]);

        return $this->guarded(fn (): PaymentBatch => $this->paymentFactory->confirmBatch($id, $validated['bank_reference'], $validated['value_date'] ?? null, (string) $request->user()?->id));
    }

    /**
     * Applies the pain.002 status report of the bank.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/pain002',
        operationId: 'importPain002TreasuryPaymentBatch',
        description: 'Applies a pain.002 status report to an exported batch. Lines reported as ACSC, ACSP, ACCP or ACWC are executed, RJCT lines are rejected, any other status leaves them waiting. Only executed lines reduce the balance and reach the ledger.',
        summary: 'Import pain.002 status report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['xml'], properties: [new OA\Property(property: 'xml', description: 'The pain.002 document', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid report, or it refers to another message', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function importPain002(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['xml' => ['required', 'string']]);

        return $this->guarded(fn (): PaymentBatch => $this->paymentFactory->importPain002($id, $validated['xml'], (string) $request->user()?->id));
    }

    /**
     * @param  callable(): PaymentBatch  $operation
     */
    private function guarded(callable $operation): JsonResponse
    {
        try {
            return response()->json(new PaymentBatchResource($operation()));
        } catch (FiscalPeriodException $e) {
            return $e->render();
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Generates and freezes the pain.001 file of an approved batch.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/export',
        operationId: 'exportTreasuryPaymentBatch',
        description: 'Generates and freezes the pain.001 file of an approved batch, downloadable afterwards. Nothing is sent to the bank: the status becomes exported and neither the balance nor the ledger change until a pain.002 import or a manual confirmation.',
        summary: 'Export payment batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'protocol', type: 'string', default: 'sepa_pain001')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'execution_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status', type: 'string', example: 'exported')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function export(Request $request, string $id): JsonResponse
    {
        return $this->execute($request, $id);
    }

    /**
     * @deprecated Use POST /api/v1/treasury/payment-batches/{id}/export: this route never contacted the bank.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/execute',
        operationId: 'executeTreasuryPaymentBatch',
        description: 'Alias of the export. The batch is not transmitted: it becomes exported and the outcome comes from a pain.002 import or a manual confirmation.',
        summary: 'Export payment batch (deprecated alias)',
        deprecated: true,
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'protocol', type: 'string', default: 'sepa_pain001')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'execution_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status', type: 'string', example: 'exported')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function execute(Request $request, string $id): JsonResponse
    {
        $protocol = (string) $request->input('protocol', 'sepa_pain001');
        $execution = $this->executeAction->execute($id, $protocol, (string) $request->user()?->id);

        return response()->json([
            'message' => 'pain.001 file generated: it must be sent to the bank, and the outcome confirmed.',
            'execution_id' => $execution->id,
            'status' => 'exported',
        ]);
    }

    /**
     * Generates the SEPA pain.001 XML file of a payment batch and returns it as an attachment.
     */
    #[OA\Get(
        path: '/api/v1/treasury/payment-batches/{id}/download-xml',
        operationId: 'getDownloadXmlTreasuryPaymentBatch',
        description: 'Returns the pain.001 XML file of a payment batch as an attachment: the frozen file once exported, a preview before.',
        summary: 'Download payment batch XML',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/xml', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function downloadXml(string $id): Response
    {
        $xml = $this->paymentFactory->generatePain001Xml($id);
        $batch = PaymentBatch::findOrFail($id);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$batch->batch_reference}.xml\"",
        ]);
    }

    /**
     * Cancels a payment batch, recording the reason.
     */
    #[OA\Post(
        path: '/api/v1/treasury/payment-batches/{id}/cancel',
        operationId: 'cancelTreasuryPaymentBatch',
        description: 'Cancels a payment batch, recording the reason.',
        summary: 'Cancel payment batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Payment Batches'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Cancelled by Treasury operator')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryPaymentBatchResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cancel(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->input('reason', 'Cancelled by Treasury operator');
        $batch = $this->paymentFactory->cancelBatch($id, $reason, (string) $request->user()?->id);

        return response()->json(new PaymentBatchResource($batch));
    }
}
