<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers\Receivables;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Http\Requests\Receivables\ReceivablesQueryRequest;
use Modules\Invoicing\Http\Requests\Receivables\SendCustomerStatementRequest;
use Modules\Invoicing\Jobs\SendCustomerStatementJob;
use Modules\Invoicing\Models\CustomerStatementDispatch;
use Modules\Invoicing\Services\Receivables\CustomerStatementExporter;
use Modules\Invoicing\Services\Receivables\CustomerStatementService;
use OpenApi\Attributes as OA;

final class CustomerStatementController extends ReceivablesController
{
    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/statement', operationId: 'getInvoicingReceivablesCustomerStatement', summary: 'Customer statement (estratto conto)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Statement with opening balance, movements, open items, aging and closing balance'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function show(string $customerId, ReceivablesQueryRequest $request, CustomerStatementService $statements): JsonResponse
    {
        return response()->json(['data' => $this->build($customerId, $request, $statements)]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/statement/pdf', operationId: 'downloadInvoicingReceivablesCustomerStatementPdf', summary: 'Customer statement as PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF document'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function pdf(string $customerId, ReceivablesQueryRequest $request, CustomerStatementService $statements, CustomerStatementExporter $exporter): Response
    {
        $statement = $this->build($customerId, $request, $statements);

        return response($exporter->pdf($statement, app()->getLocale()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement-'.$statement['period']['to'].'.pdf"',
        ]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/statement/xlsx', operationId: 'downloadInvoicingReceivablesCustomerStatementXlsx', summary: 'Customer statement as Excel workbook', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'XLSX workbook'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function xlsx(string $customerId, ReceivablesQueryRequest $request, CustomerStatementService $statements, CustomerStatementExporter $exporter): Response
    {
        $statement = $this->build($customerId, $request, $statements);

        return response($exporter->xlsx($statement, app()->getLocale()), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="statement-'.$statement['period']['to'].'.xlsx"',
        ]);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/customers/{customerId}/statement/send', operationId: 'sendInvoicingReceivablesCustomerStatement', summary: 'Queue the email dispatch of a customer statement (PDF and Excel attached)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 202, description: 'Dispatch queued'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'No recipient', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function send(string $customerId, SendCustomerStatementRequest $request): JsonResponse
    {
        $this->authorizeManage();
        $this->ensureCustomer($customerId);
        $validated = $request->validated();
        $customer = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenantId())->findOrFail($customerId);
        $recipient = $validated['recipient'] ?? $customer->email;

        if (empty($recipient)) {
            throw ValidationException::withMessages(['recipient' => __('receivables.statement.no_recipient')]);
        }

        $dispatch = CustomerStatementDispatch::create([
            'tenant_id' => $this->tenantId(),
            'customer_id' => $customerId,
            'period_from' => $validated['from'],
            'period_to' => $validated['to'],
            'recipient' => (string) $recipient,
            'locale' => $validated['locale'] ?? app()->getLocale(),
            'status' => 'queued',
            'requested_by' => $this->userId($request),
        ]);

        SendCustomerStatementJob::dispatch((string) $dispatch->id, $this->tenantId());

        return response()->json(['data' => ['id' => (string) $dispatch->id, 'status' => 'queued', 'recipient' => (string) $recipient]], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $customerId, ReceivablesQueryRequest $request, CustomerStatementService $statements): array
    {
        $this->ensureCustomer($customerId);
        $today = $this->today($request);
        $validated = $request->validated();
        $to = $validated['to'] ?? $today->toDateString();
        $from = $validated['from'] ?? CarbonImmutable::parse($to)->subMonths(3)->toDateString();

        return $statements->build($this->tenantId(), $customerId, $from, $to, $today, $validated['currency'] ?? null);
    }
}
