<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\EvaluateSupplierPerformanceAction;
use Modules\Procurement\Contracts\SupplierEvaluationServiceInterface;
use Modules\Procurement\DTOs\EvaluateSupplierDTO;
use Modules\Procurement\Http\Requests\StoreSupplierEvaluationRequest;
use Modules\Procurement\Http\Resources\SupplierScorecardResource;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierEvaluation;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Supplier Evaluations', description: 'Vendor ratings, performance reviews, and quarterly scorecards')]
final class SupplierEvaluationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/evaluations',
        summary: 'List supplier evaluations',
        description: 'Retrieve paginated supplier evaluations with vendor filtering.',
        tags: ['Procurement Supplier Evaluations'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'supplier_id', in: 'query', description: 'Filter by supplier UUID', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated evaluations list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List supplier evaluations', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $query = SupplierEvaluation::with(['supplier', 'evaluator'])
            ->orderBy('period_end', 'desc');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $evaluations = $query->paginate((int) $request->query('per_page', 25));

        return response()->json($evaluations);
    }

    #[OA\Post(
        path: '/api/v1/procurement/suppliers/{id}/evaluations',
        summary: 'Submit formal supplier evaluation scorecard',
        description: 'Records an evaluation of vendor delivery, quality, and price competitiveness.',
        tags: ['Procurement Supplier Evaluations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Supplier UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 201, description: 'Evaluation created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Submit formal supplier evaluation scorecard', tags: ['Procurement'])]
    public function store(string $supplierId, StoreSupplierEvaluationRequest $request, EvaluateSupplierPerformanceAction $action): JsonResponse
    {
        $dto = new EvaluateSupplierDTO(
            supplierId: $supplierId,
            evaluatorUserId: (string) auth()->id(),
            periodStart: $request->string('evaluation_period_start')->value(),
            periodEnd: $request->string('evaluation_period_end')->value(),
            onTimeDeliveryRate: (float) $request->input('on_time_delivery_rate'),
            qualityAcceptanceRate: (float) $request->input('quality_acceptance_rate'),
            priceCompetitivenessScore: (float) $request->input('price_competitiveness_score'),
            overallRating: $request->string('overall_rating', 'A')->value(),
            isApprovedVendor: $request->boolean('is_approved_vendor'),
            comments: $request->input('comments')
        );

        $evaluation = $action->execute($dto);

        return response()->json($evaluation, 201);
    }

    #[OA\Get(
        path: '/api/v1/procurement/suppliers/{id}/scorecard',
        summary: 'Retrieve supplier scorecard and OTIF ranking',
        description: 'Calculates composite quarterly scorecard based on historical orders, receipts, and quality audits.',
        tags: ['Procurement Supplier Evaluations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Supplier UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'year', in: 'query', description: 'Evaluation calendar year', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'quarter', in: 'query', description: 'Evaluation quarter (1-4)', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier scorecard', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSupplierScorecard')),
            new OA\Response(response: 404, description: 'Supplier not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Retrieve supplier scorecard and OTIF ranking', tags: ['Procurement'])]
    public function scorecard(string $supplierId, Request $request, SupplierEvaluationServiceInterface $service): JsonResponse
    {
        $supplier = Supplier::findOrFail($supplierId);
        $year = (int) $request->query('year', now()->year);
        $quarter = (int) $request->query('quarter', now()->quarter);

        $scorecard = $service->generateQuarterlyScorecard($supplier, $year, $quarter);

        return response()->json(new SupplierScorecardResource($scorecard));
    }
}
