<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\Treasury\Models\RibaPresentation;
use Modules\Treasury\Models\RibaReceipt;
use Modules\Treasury\Services\RibaService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class RibaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RibaService $riba) {}

    #[OA\Post(
        path: '/api/v1/treasury/riba/batches',
        operationId: 'createRibaBatch',
        description: 'Creates a draft payment batch with method `riba` from open invoices. It then follows the usual submit-approval and approve routes of the payment batches.',
        summary: 'Create Ri.Ba. batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['bank_account_id', 'sia_code', 'receipts'], properties: [
            new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'sia_code', type: 'string', maxLength: 5, minLength: 5),
            new OA\Property(property: 'batch_reference', type: 'string', nullable: true),
            new OA\Property(property: 'receipts', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'due_date', type: 'string', format: 'date'),
                new OA\Property(property: 'amount', type: 'number', nullable: true),
                new OA\Property(property: 'debtor_abi', type: 'string', nullable: true),
                new OA\Property(property: 'debtor_cab', type: 'string', nullable: true),
            ])),
        ])),
        tags: ['Treasury Ri.Ba.'],
        responses: [
            new OA\Response(response: 201, description: 'Batch created: the Ri.Ba. presentation with its receipts; payment_batch_id is the payment batch to submit and approve', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaPresentationEnvelope')),
            new OA\Response(response: 422, description: 'Invalid data, or a receipt cannot be collected', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $inTenant = static fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));

        $data = $request->validate([
            'bank_account_id' => ['required', 'uuid', $inTenant('bank_accounts')],
            'sia_code' => ['required', 'string', 'regex:/^[0-9A-Za-z]{5}$/'],
            'batch_reference' => ['nullable', 'string', 'max:20'],
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*.invoice_id' => ['required', 'uuid', 'distinct', $inTenant('invoices')],
            'receipts.*.due_date' => ['required', 'date_format:Y-m-d'],
            'receipts.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'receipts.*.debtor_abi' => ['nullable', 'regex:/^\d{5}$/'],
            'receipts.*.debtor_cab' => ['nullable', 'regex:/^\d{5}$/'],
        ]);

        return $this->guarded(fn (): RibaPresentation => $this->riba->createBatch($data, (string) $tenantId, (string) $request->user()?->id), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/treasury/riba/batches/{id}',
        operationId: 'getRibaBatch',
        description: 'A Ri.Ba. batch with its receipts and their status.',
        summary: 'Get Ri.Ba. batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Ri.Ba.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The presentation with its receipts and the payment batch', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaPresentationEnvelope')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse($this->presentationOf($id)->load(['receipts', 'paymentBatch']), 'Ri.Ba. batch retrieved');
    }

    #[OA\Post(
        path: '/api/v1/treasury/riba/batches/{id}/export',
        operationId: 'exportRibaBatch',
        description: 'Writes and freezes the CBI flat file of an approved batch. Nothing is sent to the bank.',
        summary: 'Export Ri.Ba. file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Ri.Ba.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'File written: the presentation with its receipts, now presented', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaPresentationEnvelope')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The batch is not approved', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function export(Request $request, string $id): JsonResponse
    {
        $this->presentationOf($id);

        return $this->guarded(fn (): RibaPresentation => $this->riba->export($id, $request->user()?->id));
    }

    #[OA\Get(
        path: '/api/v1/treasury/riba/batches/{id}/file',
        operationId: 'downloadRibaFile',
        description: 'The frozen CBI flat file of an exported batch.',
        summary: 'Download Ri.Ba. file',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Ri.Ba.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the payment batch', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Not found or not exported yet', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function file(string $id): HttpResponse
    {
        $presentation = $this->presentationOf($id);
        abort_if($presentation->flat_file === null, Response::HTTP_NOT_FOUND);

        return response($presentation->flat_file, 200, [
            'Content-Type' => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => "attachment; filename=\"{$presentation->file_name}\"",
        ]);
    }

    #[OA\Post(
        path: '/api/v1/treasury/riba/receipts/{id}/paid',
        operationId: 'markRibaReceiptPaid',
        description: 'Registers the collection of a presented receipt through the ordinary invoice payment flow. The amount is posted to the ledger account of the bank of the batch and added to its balance.',
        summary: 'Mark Ri.Ba. receipt paid',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['paid_at'], properties: [new OA\Property(property: 'paid_at', type: 'string', format: 'date')])),
        tags: ['Treasury Ri.Ba.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Receipt paid', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaReceiptEnvelope')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The receipt is not presented', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function paid(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['paid_at' => ['required', 'date_format:Y-m-d']]);

        return $this->guarded(fn (): RibaReceipt => $this->riba->markPaid(RibaReceipt::findOrFail($id), $validated['paid_at']));
    }

    #[OA\Post(
        path: '/api/v1/treasury/riba/receipts/{id}/unpaid',
        operationId: 'markRibaReceiptUnpaid',
        description: 'Records an insoluto. A collection already registered is undone, the invoice is open again and the balance of the bank account of the batch gives the amount back. The bank fee is stored on the receipt and posted to the ledger: debit of the account set in the tenant preference `banking.ledger_defaults.riba_unpaid_fee_account_code` (the bank charges account when unset), credit of the bank of the batch, whose balance it reduces.',
        summary: 'Mark Ri.Ba. receipt unpaid',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason', 'unpaid_at'], properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 255),
            new OA\Property(property: 'unpaid_at', type: 'string', format: 'date'),
            new OA\Property(property: 'fee', type: 'number', nullable: true),
        ])),
        tags: ['Treasury Ri.Ba.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Receipt unpaid', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryRibaReceiptEnvelope')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The receipt cannot become unpaid, the configured fee account does not exist or the period is closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function unpaid(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'unpaid_at' => ['required', 'date_format:Y-m-d'],
            'fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->guarded(fn (): RibaReceipt => $this->riba->markUnpaid(RibaReceipt::findOrFail($id), $validated));
    }

    private function presentationOf(string $batchId): RibaPresentation
    {
        return RibaPresentation::where('payment_batch_id', $batchId)->firstOrFail();
    }

    /**
     * @param  Closure(): mixed  $operation
     */
    private function guarded(Closure $operation, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return $this->successResponse($operation(), 'Operation successful', $status);
        } catch (FiscalPeriodException $e) {
            return $e->render();
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
