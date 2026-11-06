<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PassiveInvoice;
use App\Models\SupplierCostRule;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\GeneralLedger\Actions\ReclassifyPassiveInvoiceCostAction;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class SupplierCostRuleController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/purchases/cost-rules',
        operationId: 'listSupplierCostRules',
        description: 'Rules that send the supplies of a supplier, optionally only the lines with a keyword, to a cost account.',
        summary: 'List supplier cost rules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Supplier Invoices'],
        responses: [new OA\Response(response: 200, description: 'The rules', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(SupplierCostRule::query()->orderBy('supplier_id')->get(), 'Cost rules retrieved');
    }

    #[OA\Post(
        path: '/api/v1/purchases/cost-rules',
        operationId: 'storeSupplierCostRule',
        description: 'Creates a cost rule, applied to the documents received from now on.',
        summary: 'Create supplier cost rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['supplier_id', 'cost_account_id'], properties: [
            new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'keyword', type: 'string', nullable: true),
            new OA\Property(property: 'cost_account_id', type: 'string', format: 'uuid'),
        ])),
        tags: ['Supplier Invoices'],
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid', $this->inTenant('contacts')],
            'keyword' => ['nullable', 'string', 'min:2', 'max:100'],
            'cost_account_id' => ['required', 'uuid', $this->inTenant('chart_of_accounts')],
        ]);

        return $this->successResponse(SupplierCostRule::create($data), 'Cost rule created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/purchases/cost-rules/{id}',
        operationId: 'deleteSupplierCostRule',
        description: 'Deletes a cost rule. Documents already received keep their account.',
        summary: 'Delete supplier cost rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Supplier Invoices'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Rule deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        SupplierCostRule::findOrFail($id)->delete();

        return $this->successResponse(null, 'Cost rule deleted');
    }

    #[OA\Get(
        path: '/api/v1/purchases/supplier-invoices/{id}/lines',
        operationId: 'listSupplierInvoiceLines',
        description: 'Lines read from the FatturaPA file, with their cost account. Documents received before this feature have no lines.',
        summary: 'List supplier invoice lines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Supplier Invoices'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The lines', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function lines(string $id): JsonResponse
    {
        return $this->successResponse(PassiveInvoice::findOrFail($id)->lines()->get(), 'Supplier invoice lines retrieved');
    }

    #[OA\Put(
        path: '/api/v1/purchases/supplier-invoices/{id}/cost-account',
        operationId: 'overrideSupplierInvoiceCostAccount',
        description: 'Overrides the cost account of a document, or of some of its lines. When the document is already posted, a reclassification entry dated today moves the amount between the accounts.',
        summary: 'Override supplier invoice cost account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['cost_account_id'], properties: [
            new OA\Property(property: 'cost_account_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'line_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        ])),
        tags: ['Supplier Invoices'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Override applied', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function overrideCostAccount(Request $request, string $id, ReclassifyPassiveInvoiceCostAction $reclassify): JsonResponse
    {
        $invoice = PassiveInvoice::findOrFail($id);

        $data = $request->validate([
            'cost_account_id' => ['required', 'uuid', $this->inTenant('chart_of_accounts')],
            'line_ids' => ['nullable', 'array'],
            'line_ids.*' => ['uuid', Rule::exists('passive_invoice_lines', 'id')->where('passive_invoice_id', $invoice->id)],
        ]);

        return $this->successResponse($reclassify->execute($invoice, $data['cost_account_id'], $data['line_ids'] ?? []), 'Cost account updated');
    }

    private function inTenant(string $table): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, 'id')->where(static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
