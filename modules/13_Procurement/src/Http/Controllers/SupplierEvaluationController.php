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

final class SupplierEvaluationController extends Controller
{
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
