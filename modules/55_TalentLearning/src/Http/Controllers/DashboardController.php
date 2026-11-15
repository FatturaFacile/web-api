<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Models\EngagementSurvey;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Services\LearningService;
use Modules\TalentLearning\Services\SurveyService;
use OpenApi\Attributes as OA;

/**
 * Summary of learning and engagement, for learning managers.
 */
final class DashboardController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly LearningService $learning, private readonly SurveyService $surveys) {}

    #[OA\Get(path: '/api/v1/talent-learning/dashboard', operationId: 'showTalentLearningDashboard', description: 'Published courses and paths, enrolments by status, completion rate, overdue enrolments, certificates issued, expired and expiring within 90 days, the compliance with mandatory training, and the participation and eNPS of the latest survey (the eNPS only when the anonymity threshold is reached).', summary: 'Learning and engagement dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentLearningDashboard')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function summary(): JsonResponse
    {
        $this->authorize('manage', LmsCourse::class);
        $tenantId = $this->tenantId();
        $latest = EngagementSurvey::query()->where('tenant_id', $tenantId)->whereIn('status', [EngagementSurvey::Open, EngagementSurvey::Closed])->orderByDesc('opens_on')->orderByDesc('created_at')->first();
        $engagement = null;

        if ($latest !== null) {
            $results = $this->surveys->results($latest);
            $engagement = [
                'survey_id' => $latest->id,
                'title' => $latest->title,
                'status' => $latest->status,
                'responses_count' => $results['responses_count'],
                'participation_rate_percent' => $results['participation_rate_percent'],
                'results_visible' => $results['results_visible'],
                'enps' => $this->surveys->enps($latest),
            ];
        }

        return response()->json(['data' => [
            'learning' => $this->learning->summary($tenantId),
            'engagement' => $engagement,
            'surveys_open' => EngagementSurvey::query()->where('tenant_id', $tenantId)->where('status', EngagementSurvey::Open)->count(),
        ]]);
    }
}
