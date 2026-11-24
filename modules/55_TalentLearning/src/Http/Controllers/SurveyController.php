<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\SurveyRequest;
use Modules\TalentLearning\Http\Requests\SurveyResponseRequest;
use Modules\TalentLearning\Http\Resources\SurveyResource;
use Modules\TalentLearning\Models\EngagementSurvey;
use Modules\TalentLearning\Services\SurveyService;
use OpenApi\Attributes as OA;

/**
 * Engagement surveys: definition, lifecycle, answering and aggregated results.
 */
final class SurveyController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly SurveyService $surveys) {}

    #[OA\Get(path: '/api/v1/talent-learning/surveys', operationId: 'listTalentSurveys', description: 'Surveys, latest first. Filter: status. Members who do not manage learning see the open surveys only, each with has_answered.', summary: 'List engagement surveys', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Surveys', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentSurvey'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EngagementSurvey::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in([EngagementSurvey::Draft, EngagementSurvey::Open, EngagementSurvey::Closed])]]);
        $manages = $request->user()?->can('manage', EngagementSurvey::class) ?? false;

        $page = EngagementSurvey::query()->where('tenant_id', $this->tenantId())
            ->when(! $manages, static fn ($query) => $query->where('status', EngagementSurvey::Open))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')->paginate($this->perPage());

        foreach ($page->getCollection() as $survey) {
            $survey->setAttribute('has_answered', $this->surveys->hasAnswered($survey, $this->userId()));
        }

        return SurveyResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/talent-learning/surveys', operationId: 'storeTalentSurvey', description: 'Create a survey in draft with its questions: rating (1 to 5), enps (0 to 10) or text. An anonymous survey (the default) never stores who answered and only shows results for groups of at least min_group_size (3 to 50, default 5) responses.', summary: 'Create an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSurveyRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Survey created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSurvey')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(SurveyRequest $request): JsonResponse
    {
        $this->authorize('create', EngagementSurvey::class);

        return (new SurveyResource($this->surveys->save($this->tenantId(), $request->validated(), null)->load('questions')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/surveys/{id}', operationId: 'showTalentSurvey', description: 'Return a survey with its questions. A member who does not manage learning can only read an open survey.', summary: 'Show an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Survey', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSurvey')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(Request $request, string $id): SurveyResource
    {
        $survey = EngagementSurvey::query()->with('questions')->findOrFail($id);
        $this->authorize('view', $survey);
        abort_unless($survey->status === EngagementSurvey::Open || $request->user()?->can('manage', EngagementSurvey::class), 404);
        $survey->setAttribute('has_answered', $this->surveys->hasAnswered($survey, $this->userId()));

        return new SurveyResource($survey);
    }

    #[OA\Put(path: '/api/v1/talent-learning/surveys/{id}', operationId: 'updateTalentSurvey', description: 'Change a draft survey; questions replaces the whole list.', summary: 'Update an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSurveyRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Survey updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSurvey')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(SurveyRequest $request, string $id): SurveyResource
    {
        $survey = EngagementSurvey::query()->findOrFail($id);
        $this->authorize('update', $survey);

        return new SurveyResource($this->surveys->save($this->tenantId(), $request->validated(), $survey)->load('questions'));
    }

    #[OA\Delete(path: '/api/v1/talent-learning/surveys/{id}', operationId: 'deleteTalentSurvey', description: 'Delete a draft survey (soft delete).', summary: 'Delete an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Survey deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $survey = EngagementSurvey::query()->findOrFail($id);
        $this->authorize('delete', $survey);
        $this->surveys->delete($survey);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/talent-learning/surveys/{id}/open', operationId: 'openTalentSurvey', description: 'Open a draft survey with at least one question: employees can answer it.', summary: 'Open an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Survey opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSurvey')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function open(string $id): SurveyResource
    {
        $survey = EngagementSurvey::query()->findOrFail($id);
        $this->authorize('update', $survey);

        return new SurveyResource($this->surveys->open($survey)->load('questions'));
    }

    #[OA\Post(path: '/api/v1/talent-learning/surveys/{id}/close', operationId: 'closeTalentSurvey', description: 'Close an open survey: no more answers.', summary: 'Close an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Survey closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSurvey')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function close(string $id): SurveyResource
    {
        $survey = EngagementSurvey::query()->findOrFail($id);
        $this->authorize('update', $survey);

        return new SurveyResource($this->surveys->close($survey)->load('questions'));
    }

    #[OA\Get(path: '/api/v1/talent-learning/surveys/{id}/results', operationId: 'showTalentSurveyResults', description: 'Aggregated results: participation, average and distribution of rating questions, eNPS (promoters 9-10, detractors 0-6) and free text answers. With an anonymous survey nothing is shown until min_group_size responses exist, and departments under that size are merged into an "other" group (hidden_segments tells when a group stays hidden). For learning managers.', summary: 'Engagement survey results', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Results', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSurveyResults')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function results(string $id): JsonResponse
    {
        $this->authorize('manage', EngagementSurvey::class);
        $survey = EngagementSurvey::query()->findOrFail($id);
        $this->authorize('view', $survey);

        return response()->json(['data' => $this->surveys->results($survey)]);
    }

    #[OA\Post(path: '/api/v1/talent-learning/surveys/{id}/responses', operationId: 'submitTalentSurveyResponse', description: 'The employee linked to the logged-in user answers an open survey, once. Rating answers are 1 to 5, eNPS answers 0 to 10, required questions must be answered. With an anonymous survey the answers are stored without the respondent and cannot be traced back. Responds 204.', summary: 'Answer an engagement survey', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSurveyResponseRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Answers recorded'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function respond(SurveyResponseRequest $request, string $id): Response
    {
        $survey = EngagementSurvey::query()->findOrFail($id);
        $this->authorize('view', $survey);
        $this->surveys->submit($survey, $this->userId(), $request->validated('answers'));

        return response()->noContent();
    }
}
