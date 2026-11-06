<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsLedgerPosting;
use Modules\Customs\Services\CustomsLedgerService;
use Modules\Customs\Services\CustomsReconciliationService;
use OpenApi\Attributes as OA;

/**
 * Duty and import VAT in the ledger. Reserved to finance, whatever the access to the module.
 */
final class CustomsLedgerController extends CustomsController
{
    public function __construct(
        TenantContext $tenantContext,
        private readonly CustomsLedgerService $ledger,
        private readonly CustomsReconciliationService $reconciliation,
    ) {
        parent::__construct($tenantContext);
    }

    /**
     * Post a decision to the ledger.
     */
    #[OA\Post(path: '/api/v1/customs/assessment-decisions/{id}/post', operationId: 'postCustomsAssessmentDecision', description: 'Post duty or import VAT of a decision through the General Ledger. A corrected version posts the difference. Posting twice changes nothing.', summary: 'Post assessment decision', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Posting', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function post(Request $request, string $id): CustomsRecordResource
    {
        $decision = CustomsAssessmentDecision::query()->findOrFail($id);
        $this->authorize('post', $decision);

        return new CustomsRecordResource($this->ledger->post($decision, $this->actorId($request)));
    }

    /**
     * Reconcile duty and VAT with the ledger.
     */
    #[OA\Get(path: '/api/v1/customs/ledger/reconciliation', operationId: 'customsLedgerReconciliation', description: 'Compare the duty and VAT assessed in a period with the customs payable account of the ledger, document by document.', summary: 'Customs ledger reconciliation', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [
        new OA\Parameter(name: 'date_from', description: 'First document date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', description: 'Last document date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Reconciliation', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function reconciliation(Request $request): JsonResponse
    {
        $this->authorize('reconcile', CustomsLedgerPosting::class);

        $period = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]);

        return response()->json(['data' => $this->reconciliation->forPeriod($this->tenant()->id, $period['date_from'], $period['date_to'])]);
    }
}
