<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\InvoiceMatchToleranceRuleRequest;
use Modules\Procurement\Http\Resources\InvoiceMatchToleranceRuleResource;
use Modules\Procurement\Models\InvoiceMatchToleranceRule;
use Modules\Procurement\Services\InvoiceMatchToleranceRuleService;
use OpenApi\Attributes as OA;

/**
 * Tolerance rules of the invoice matching, scoped to the tenant, a supplier, an item category or a named rule.
 */
final class InvoiceMatchToleranceRuleController extends Controller
{
    use ApiResponse;
    use ProcurementControllerHelpers;

    public function __construct(private readonly InvoiceMatchToleranceRuleService $rules) {}

    #[OA\Get(path: '/api/v1/procurement/invoice-matching/tolerance-rules', operationId: 'listInvoiceMatchToleranceRules', summary: 'Tolerance rules of the invoice matching', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The rules, narrowest scope first')])]
    public function index(): JsonResponse
    {
        return $this->successResponse(InvoiceMatchToleranceRuleResource::collection($this->rules->all()), __('procurement.invoice_matching.messages.rules_retrieved'));
    }

    #[OA\Post(path: '/api/v1/procurement/invoice-matching/tolerance-rules', operationId: 'createInvoiceMatchToleranceRule', summary: 'Create a tolerance rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 201, description: 'The rule'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(InvoiceMatchToleranceRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->create($this->tenantId(), $request->validated());

        return $this->successResponse(new InvoiceMatchToleranceRuleResource($rule), __('procurement.invoice_matching.messages.rule_created'), 201);
    }

    #[OA\Put(path: '/api/v1/procurement/invoice-matching/tolerance-rules/{id}', operationId: 'updateInvoiceMatchToleranceRule', summary: 'Update a tolerance rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'The rule'), new OA\Response(response: 404, description: 'Not found')])]
    public function update(InvoiceMatchToleranceRuleRequest $request, string $id): JsonResponse
    {
        $rule = $this->rules->update(InvoiceMatchToleranceRule::query()->findOrFail($id), $request->validated());

        return $this->successResponse(new InvoiceMatchToleranceRuleResource($rule), __('procurement.invoice_matching.messages.rule_updated'));
    }

    #[OA\Delete(path: '/api/v1/procurement/invoice-matching/tolerance-rules/{id}', operationId: 'deleteInvoiceMatchToleranceRule', summary: 'Delete a tolerance rule', description: 'Earlier matching runs keep the tolerance they were evaluated with.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Procurement Invoice Matching'], responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 404, description: 'Not found')])]
    public function destroy(string $id): JsonResponse
    {
        $this->rules->delete(InvoiceMatchToleranceRule::query()->findOrFail($id));

        return $this->successResponse(null, __('procurement.invoice_matching.messages.rule_deleted'));
    }
}
