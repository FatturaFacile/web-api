<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\ProcurementPeriodRequest;
use Modules\Procurement\Http\Requests\SrmScorecardsRequest;
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
        description: 'Composite 0-100 rating and A-D tier of every supplier ordered from, invoiced or invited to a tender in the period, filtered by name and tier, sorted by score, name or a component, one page at a time.',
        summary: 'Supplier rating ranking',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement SRM'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/ProcDateFrom'),
            new OA\Parameter(ref: '#/components/parameters/ProcDateTo'),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'tier', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['A', 'B', 'C', 'D'])),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'score', enum: ['score', 'name', 'on_time_delivery_rate', 'quality_acceptance_rate', 'price_competitiveness_score', 'invoice_accuracy_rate', 'rfq_responsiveness_rate'])),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ranking page', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'meta', type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function scorecards(SrmScorecardsRequest $request): JsonResponse
    {
        return response()->json($this->ratings->page($this->tenantId(), $request->from(), $request->to(), $request->listing()));
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
