<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\AiCognitive\Application\Commands\GeneratePredictiveInsightsCommand;
use Modules\AiCognitive\Application\Handlers\GeneratePredictiveInsightsHandler;
use Modules\AiCognitive\Application\Handlers\GetPredictiveDashboardHandler;
use Modules\AiCognitive\Application\Queries\GetPredictiveDashboardQuery;
use Modules\AiCognitive\Presentation\Http\Requests\AiListRequest;
use Modules\AiCognitive\Presentation\Http\Requests\RunAiJobRequest;
use Modules\AiCognitive\Presentation\Http\Resources\AiSuggestionResource;
use OpenApi\Attributes as OA;

final class AiInsightController extends AiApiController
{
    #[OA\Get(path: '/api/v1/ai/insights', operationId: 'getAiPredictiveDashboard', description: 'The Predictive Insights and Anomaly Center: the cash flow projection with confidence bands, customers by payment risk, and the open recommendations (cash alert, late payment, churn, reorder) and anomaly alerts.', summary: 'Predictive insights and anomalies', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'The dashboard', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function dashboard(AiListRequest $request, GetPredictiveDashboardHandler $handler): JsonResponse
    {
        $data = $request->validated();
        $result = $handler->handle(new GetPredictiveDashboardQuery($this->tenantId(), isset($data['horizon_days']) ? (int) $data['horizon_days'] : null));

        return $this->successResponse([
            'cash_flow' => $result['cash_flow'],
            'payment_risk' => $result['payment_risk'],
            'recommendations' => AiSuggestionResource::collection(collect($result['recommendations']))->resolve(),
            'anomalies' => AiSuggestionResource::collection(collect($result['anomalies']))->resolve(),
        ]);
    }

    #[OA\Post(path: '/api/v1/ai/insights/generate', operationId: 'generateAiPredictiveInsights', description: 'Generates the predictive recommendations and runs the anomaly scan of the last 30 days now (they also run nightly).', summary: 'Generate predictive insights', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'Counts of what was found', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function generate(RunAiJobRequest $request, GeneratePredictiveInsightsHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            $handler->handle(new GeneratePredictiveInsightsCommand($this->tenantId(), $this->userId(), (bool) ($data['with_anomaly_scan'] ?? true))),
            'Insights generated',
        ));
    }
}
