<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\HealthExpenses\Http\Requests\StoreHealthExpenseRequest;
use Modules\HealthExpenses\Models\HealthExpense;
use Modules\HealthExpenses\Models\HealthExpenseSetting;
use Modules\HealthExpenses\Services\HealthExpenseExporter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tessera Sanitaria: settings, flagged health expenses and the yearly data export of the active tenant.
 */
final class HealthExpenseController extends Controller
{
    use ApiResponse;

    /**
     * Inject the tenant context.
     */
    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/tessera-sanitaria/settings',
        operationId: 'getHealthExpenseSettings',
        description: 'Sistema Tessera Sanitaria settings of the active tenant. The fiscal code of the owner is never returned.',
        summary: 'Get Tessera Sanitaria settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Tessera Sanitaria'],
        responses: [
            new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    /**
     * Show the settings of the active tenant (the owner fiscal code is hidden).
     */
    public function settings(): JsonResponse
    {
        return $this->successResponse($this->currentSettings(), 'Settings retrieved');
    }

    #[OA\Put(
        path: '/api/v1/tessera-sanitaria/settings',
        operationId: 'updateHealthExpenseSettings',
        description: 'Turns the Sistema Tessera Sanitaria flow on or off for the active tenant.',
        summary: 'Update Tessera Sanitaria settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['is_enabled'], properties: [
            new OA\Property(property: 'is_enabled', type: 'boolean'),
            new OA\Property(property: 'subject_type', type: 'string', enum: ['professional', 'structure']),
            new OA\Property(property: 'owner_fiscal_code', type: 'string', nullable: true),
            new OA\Property(property: 'region_code', type: 'string', nullable: true),
            new OA\Property(property: 'asl_code', type: 'string', nullable: true),
            new OA\Property(property: 'ssa_code', type: 'string', nullable: true),
        ])),
        tags: ['Tessera Sanitaria'],
        responses: [
            new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    /**
     * Update the settings of the active tenant.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'subject_type' => ['nullable', 'in:professional,structure'],
            'owner_fiscal_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{11,16}$/'],
            'region_code' => ['nullable', 'string', 'max:3'],
            'asl_code' => ['nullable', 'string', 'max:3'],
            'ssa_code' => ['nullable', 'string', 'max:6'],
        ]);

        $settings = $this->currentSettings();
        $settings->fill(array_filter($validated, static fn (mixed $value): bool => $value !== null))->save();

        return $this->successResponse($settings, 'Settings updated');
    }

    #[OA\Get(
        path: '/api/v1/tessera-sanitaria/expenses',
        operationId: 'listHealthExpenses',
        description: 'Health expenses flagged on documents. Filter by `year` of payment.',
        summary: 'List health expenses',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Tessera Sanitaria'],
        parameters: [new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Paginated list', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    /**
     * List the flagged expenses, optionally of one payment year.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['year' => ['nullable', 'integer', 'min:2014', 'max:2100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $expenses = HealthExpense::query()
            ->when($validated['year'] ?? null, static fn ($query, int|string $year) => $query->whereYear('payment_date', $year))
            ->latest('payment_date')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse($expenses->items(), 'Health expenses retrieved', meta: ['total' => $expenses->total(), 'current_page' => $expenses->currentPage(), 'last_page' => $expenses->lastPage()]);
    }

    #[OA\Post(
        path: '/api/v1/tessera-sanitaria/expenses',
        operationId: 'storeHealthExpense',
        description: 'Flags an invoice or a commercial document as a health expense. The fiscal code of the patient is stored encrypted, and dropped when the patient opposes the transmission.',
        summary: 'Flag a health expense',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_type', 'document_id', 'expense_type', 'payment_date', 'payment_traceable'], properties: [
            new OA\Property(property: 'document_type', type: 'string', enum: ['invoice', 'commercial_document']),
            new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'expense_type', type: 'string', enum: ['TK', 'FC', 'FV', 'SV', 'SP', 'AD', 'AS', 'SR', 'CT', 'PI', 'IC', 'AA']),
            new OA\Property(property: 'expense_flag', type: 'string', nullable: true),
            new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
            new OA\Property(property: 'amount', type: 'number', nullable: true),
            new OA\Property(property: 'tax_rate', type: 'number', nullable: true),
            new OA\Property(property: 'vat_nature', type: 'string', nullable: true),
            new OA\Property(property: 'patient_fiscal_code', type: 'string', nullable: true),
            new OA\Property(property: 'patient_opposition', type: 'boolean'),
            new OA\Property(property: 'payment_traceable', type: 'boolean'),
        ])),
        tags: ['Tessera Sanitaria'],
        responses: [
            new OA\Response(response: 201, description: 'Expense flagged', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Document not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Flow disabled or invalid data', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    /**
     * Flag a tenant invoice or commercial document as a health expense.
     */
    public function store(StoreHealthExpenseRequest $request): JsonResponse
    {
        if (! $this->currentSettings()->is_enabled) {
            throw ValidationException::withMessages(['tessera_sanitaria' => __('sales.tessera_sanitaria_disabled')]);
        }

        $data = $request->validated();
        $isInvoice = $data['document_type'] === 'invoice';
        $document = $isInvoice ? Invoice::findOrFail($data['document_id']) : CommercialDocument::findOrFail($data['document_id']);
        $issueDate = $isInvoice ? $document->date : $document->issue_date;
        $opposition = (bool) ($data['patient_opposition'] ?? false);

        if ($issueDate->toDateString() > $data['payment_date'] && ! ($data['is_advance_payment'] ?? false)) {
            throw ValidationException::withMessages(['payment_date' => __('sales.tessera_sanitaria_payment_before_issue')]);
        }

        $expense = HealthExpense::create([
            ...$data,
            'document_kind' => $isInvoice ? 'F' : 'D',
            'document_number' => $isInvoice ? $document->invoice_number : $document->document_number,
            'issue_date' => $issueDate,
            'amount' => $data['amount'] ?? ($isInvoice ? $document->total_gross : $document->total_gross),
            'patient_fiscal_code' => $opposition ? null : ($data['patient_fiscal_code'] ?? null),
            'patient_opposition' => $opposition,
        ]);

        return $this->successResponse($expense, 'Health expense flagged', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/tessera-sanitaria/expenses/{id}',
        operationId: 'deleteHealthExpense',
        description: 'Removes the health expense flag from a document.',
        summary: 'Remove a health expense flag',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Tessera Sanitaria'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Flag removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    /**
     * Remove the flag from a document.
     */
    public function destroy(string $id): JsonResponse
    {
        HealthExpense::findOrFail($id)->delete();

        return $this->successResponse(null, 'Health expense flag removed');
    }

    #[OA\Get(
        path: '/api/v1/tessera-sanitaria/export',
        operationId: 'exportHealthExpenses',
        description: 'Data of the health expenses paid in a year, by the field names of the published Sistema TS document. This is NOT the official file: the XSD was not verified and fiscal codes are not encrypted for the Sistema TS, so `format_status` is `unverified_data_layer`.',
        summary: 'Export health expense data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Tessera Sanitaria'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'json', enum: ['json', 'csv'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The rows', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Invalid period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    /**
     * Export the expenses paid in a year as JSON rows or CSV.
     */
    public function export(Request $request, HealthExpenseExporter $exporter, ExcelCsvWriter $csv): JsonResponse|HttpResponse
    {
        $validated = $request->validate(['year' => ['required', 'integer', 'min:2014', 'max:2100'], 'format' => ['nullable', 'in:json,csv']]);
        $rows = $exporter->rows($this->tenantContext->getTenant(), (int) $validated['year']);

        if (($validated['format'] ?? 'json') === 'csv') {
            return response($csv->write(HealthExpenseExporter::Columns, array_map(array_values(...), $rows)), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"spese-sanitarie-{$validated['year']}.csv\"",
                'X-Format-Status' => HealthExpenseExporter::FormatStatus,
            ]);
        }

        return $this->successResponse(['format_status' => HealthExpenseExporter::FormatStatus, 'year' => (int) $validated['year'], 'rows' => $rows], 'Health expense data exported');
    }

    /**
     * The settings row of the tenant, unsaved with defaults when none exists yet.
     */
    private function currentSettings(): HealthExpenseSetting
    {
        return HealthExpenseSetting::firstOrNew(['tenant_id' => $this->tenantContext->getTenantId()], ['is_enabled' => false, 'subject_type' => 'professional']);
    }
}
