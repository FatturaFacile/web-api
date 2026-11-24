<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccountsPayable\Exceptions\AccountsPayableException;
use Modules\AccountsPayable\Http\Controllers\Concerns\ResolvesAccountsPayableContext;
use Modules\AccountsPayable\Http\Requests\ParseClauseRequest;
use Modules\AccountsPayable\Http\Requests\SupplierTermRequest;
use Modules\AccountsPayable\Http\Resources\SupplierTermResource;
use Modules\AccountsPayable\Services\SupplierTermService;
use OpenApi\Attributes as OA;

/**
 * The cash-discount (Skonto) agreements with the suppliers.
 */
final class SupplierTermController extends Controller
{
    use ApiResponse;
    use ResolvesAccountsPayableContext;

    public function __construct(private readonly SupplierTermService $terms) {}

    #[OA\Get(path: '/api/v1/accounts-payable/supplier-terms', operationId: 'listApSupplierTerms', summary: 'Cash-discount agreements with the suppliers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The agreements')])]
    public function index(Request $request): JsonResponse
    {
        $supplierId = $request->query('supplier_id');

        return $this->successResponse(SupplierTermResource::collection($this->terms->list($this->tenantId(), is_string($supplierId) && $supplierId !== '' ? $supplierId : null)), __('accounts_payable.messages.supplier_terms_retrieved'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/supplier-terms', operationId: 'createApSupplierTerm', summary: 'Register a cash-discount agreement', description: 'Give skonto_percentage and skonto_days, or the text of the clause (30 gg data fattura, 2% skonto entro 10 gg).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 201, description: 'The agreement'), new OA\Response(response: 422, description: 'The terms are not valid')])]
    public function store(SupplierTermRequest $request): JsonResponse
    {
        return $this->successResponse(new SupplierTermResource($this->terms->create($this->tenantId(), $request->validated())), __('accounts_payable.messages.supplier_term_saved'), 201);
    }

    #[OA\Put(path: '/api/v1/accounts-payable/supplier-terms/{id}', operationId: 'updateApSupplierTerm', summary: 'Change a cash-discount agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The agreement')])]
    public function update(SupplierTermRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(new SupplierTermResource($this->terms->update($this->tenantId(), $id, $request->validated())), __('accounts_payable.messages.supplier_term_saved'));
    }

    #[OA\Delete(path: '/api/v1/accounts-payable/supplier-terms/{id}', operationId: 'deleteApSupplierTerm', summary: 'Remove a cash-discount agreement', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->terms->delete($this->tenantId(), $id);

        return $this->successResponse(null, __('accounts_payable.messages.supplier_term_deleted'));
    }

    #[OA\Post(path: '/api/v1/accounts-payable/supplier-terms/parse', operationId: 'parseApPaymentClause', summary: 'Read the cash-discount terms of a payment clause', description: 'Nothing is saved.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Accounts Payable'], responses: [new OA\Response(response: 200, description: 'The terms found'), new OA\Response(response: 422, description: 'The clause carries no discount')])]
    public function parse(ParseClauseRequest $request): JsonResponse
    {
        $terms = $this->terms->parse((string) $request->validated('clause'));

        if ($terms === null) {
            throw AccountsPayableException::invalid('clause_not_understood');
        }

        return $this->successResponse(['skonto_percentage' => $terms->percentage, 'skonto_days' => $terms->days, 'net_days' => $terms->netDays], __('accounts_payable.messages.clause_parsed'));
    }
}
