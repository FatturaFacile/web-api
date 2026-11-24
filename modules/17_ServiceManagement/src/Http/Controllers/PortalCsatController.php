<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Actions\SubmitCSATAction;
use Modules\ServiceManagement\Http\Requests\AnswerCsatSurveyRequest;
use Modules\ServiceManagement\Http\Resources\CSATSurveyResource;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Services\Helpdesk\CsatSurveyService;
use OpenApi\Attributes as OA;

/**
 * The satisfaction survey link sent on resolution. The secret token identifies the survey and its tenant; the answer
 * goes through the same action as the CSAT endpoint.
 */
final class PortalCsatController extends Controller
{
    public function __construct(private readonly CsatSurveyService $surveys) {}

    #[OA\Get(path: '/api/v1/portal/csat/{token}', operationId: 'showPortalCsatSurvey', description: 'Returns the ticket a satisfaction survey is about and whether it can still be answered.', summary: 'Show satisfaction survey', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskSurveyToken')], responses: [
        new OA\Response(response: 200, description: 'Survey', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskPortalCsatSurvey')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $token): JsonResponse
    {
        $survey = $this->surveys->resolve($token);

        return response()->json(['data' => [
            'ticket_number' => $survey->ticket?->ticket_number,
            'subject' => $survey->ticket?->subject,
            'status' => $survey->status,
            'can_answer' => $survey->status === CSATSurvey::StatusPending && ($survey->expires_at === null || $survey->expires_at->isFuture()),
            'expires_at' => $survey->expires_at?->toIso8601String(),
        ]]);
    }

    #[OA\Post(path: '/api/v1/portal/csat/{token}', operationId: 'answerPortalCsatSurvey', description: 'Answers a satisfaction survey: a score from 1 to 5 and an optional comment. A survey is answered once, before its link expires.', summary: 'Answer satisfaction survey', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskSurveyToken')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskAnswerCsatSurveyRequest')), responses: [
        new OA\Response(response: 200, description: 'Answer recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementCSATSurveyResource')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Already answered, expired, or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function answer(AnswerCsatSurveyRequest $request, string $token, SubmitCSATAction $action): JsonResponse
    {
        $survey = $this->surveys->resolve($token);

        return response()->json(['data' => new CSATSurveyResource($action->complete($survey, (int) $request->validated('score'), $request->validated('feedback')))]);
    }
}
