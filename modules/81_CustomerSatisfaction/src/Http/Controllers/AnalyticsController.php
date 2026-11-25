<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Http\Requests\ListFiltersRequest;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use Modules\CustomerSatisfaction\Services\SatisfactionAnalyticsService;
use OpenApi\Attributes as OA;

/**
 * Live ISO 9001 § 9.1.2 analytics: KPIs, breakdowns, trend and sentiment.
 */
final class AnalyticsController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly SatisfactionAnalyticsService $analytics) {}

    #[OA\Get(path: '/api/v1/customer-satisfaction/analytics/overview', operationId: 'customerSatisfactionAnalyticsOverview', summary: 'NPS, CSAT, CES, response rate and closed-loop SLA of a period', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'KPIs')])]
    public function overview(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyResponse::class);

        return $this->successResponse($this->analytics->overview($request->validated()), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/analytics/breakdown', operationId: 'customerSatisfactionAnalyticsBreakdown', summary: 'KPIs by department, technician, product line or branch', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'KPIs per dimension value')])]
    public function breakdown(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyResponse::class);
        $groupBy = ReportGroupBy::tryFrom((string) $request->input('group_by', 'department')) ?? ReportGroupBy::Department;

        return $this->successResponse(['group_by' => $groupBy->value, 'rows' => $this->analytics->breakdown($groupBy, $request->validated())], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/analytics/trend', operationId: 'customerSatisfactionAnalyticsTrend', summary: 'KPIs over time (day, week, month, quarter)', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'KPI time series')])]
    public function trend(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyResponse::class);
        $granularity = (string) $request->input('granularity', 'month');

        return $this->successResponse(['granularity' => $granularity, 'rows' => $this->analytics->trend($granularity, $request->validated())], $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/analytics/sentiment', operationId: 'customerSatisfactionAnalyticsSentiment', summary: 'Sentiment and recurring themes of the open comments', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Sentiment summary')])]
    public function sentiment(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyResponse::class);

        return $this->successResponse($this->analytics->sentiment($request->validated()), $this->message('loaded'));
    }
}
