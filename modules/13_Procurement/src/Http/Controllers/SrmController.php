<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\ProcurementPeriodRequest;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Services\SrmDashboardService;
use Modules\Procurement\Services\SupplierRatingService;
use OpenApi\Attributes as OA;

/**
 * Purchasing and supplier relationship overview: the executive dashboard and the composite supplier ratings.
 */
#[OA\Tag(name: 'Procurement SRM', description: 'Purchasing overview dashboard and composite supplier ratings')]
final class SrmController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(
        private readonly SrmDashboardService $dashboard,
        private readonly SupplierRatingService $ratings,
    ) {}

    /**
     * Purchasing and SRM overview.
     */
    #[OA\Get(
        path: '/api/v1/procurement/srm/dashboard',
        operationId: 'procurementSrmDashboard',
        description: 'Pending requisitions, open tenders, open and overdue orders, receipts to inspect or invoice, invoices blocked by the 3-way match, spend by supplier and category, supplier qualification figures and the best and worst supplier ratings of the period.',
        summary: 'Purchasing and SRM dashboard',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement SRM'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'), new OA\Parameter(ref: '#/components/parameters/ProcDateTo')],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function dashboard(ProcurementPeriodRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->overview($this->tenantId(), $request->from(), $request->to())]);
    }

    /**
     * Ratings of the suppliers active in the period, best first.
     */
    #[OA\Get(
        path: '/api/v1/procurement/srm/scorecards',
        operationId: 'procurementSrmScorecards',
        description: 'Composite 0-100 rating and A-D tier of every supplier ordered from, invoiced or invited to a tender in the period, best first.',
        summary: 'Supplier rating ranking',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement SRM'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'), new OA\Parameter(ref: '#/components/parameters/ProcDateTo')],
        responses: [
            new OA\Response(response: 200, description: 'Ranking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function scorecards(ProcurementPeriodRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->ratings->ranking($this->tenantId(), $request->from(), $request->to(), 100)]);
    }

    /**
     * Composite rating of one supplier.
     */
    #[OA\Get(
        path: '/api/v1/procurement/suppliers/{id}/srm-scorecard',
        operationId: 'procurementSupplierSrmScorecard',
        description: 'On-time delivery, quality, price accuracy, invoice accuracy (3-way match exceptions) and RFQ responsiveness of the supplier with the weights, the composite 0-100 score and the A-D tier.',
        summary: 'Supplier composite scorecard',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement SRM'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'),
            new OA\Parameter(ref: '#/components/parameters/ProcDateTo'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scorecard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(response: 404, description: 'Supplier not found'),
        ]
    )]
    public function supplierScorecard(string $id, ProcurementPeriodRequest $request): JsonResponse
    {
        $supplier = Supplier::query()->findOrFail($id);

        return response()->json(['data' => $this->ratings->rate($supplier, $request->from(), $request->to())]);
    }
}
