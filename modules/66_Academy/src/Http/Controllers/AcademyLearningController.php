<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Academy\Commands\StartAcademyMissionCommand;
use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Http\Requests\AcademyLocaleRequest;
use Modules\Academy\Http\Requests\ContextualHelpRequest;
use Modules\Academy\Http\Resources\AcademyArrayResource;
use Modules\Academy\Http\Resources\ContextualHelpResource;
use Modules\Academy\Http\Resources\MissionProgressResource;
use Modules\Academy\Models\InteractiveMission;
use Modules\Academy\Models\UserAcademyProgress;
use Modules\Academy\Queries\GetAcademyTrackProgressQuery;
use Modules\Academy\Queries\GetContextualHelpForRouteQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contextual help, learning tracks, missions and the progress of the caller.
 */
final class AcademyLearningController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/academy/contextual-help',
        operationId: 'getAcademyContextualHelp',
        description: 'The help article of an ERP route with its field tooltips. The language falls back it-CH to it-IT to en; the language actually served is in `locale`.',
        summary: 'Contextual help for an ERP route',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [
            new OA\Parameter(name: 'route', in: 'query', required: true, schema: new OA\Schema(type: 'string'), example: '/invoices/new'),
            new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT', 'it', 'de', 'fr'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Help article', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyContextualHelpResource')])),
            new OA\Response(response: 404, description: 'No help for this route', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function contextualHelp(ContextualHelpRequest $request, GetContextualHelpForRouteQuery $query): JsonResponse
    {
        $help = $query->execute((string) $request->validated('route'), AcademyLocale::fromInput($request->validated('locale') ?? $request->user()->locale ?? app()->getLocale()));

        if ($help === null) {
            return $this->problemDetailsResponse('Not found', 'There is no help article for this route.', Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(new ContextualHelpResource($help));
    }

    #[OA\Get(
        path: '/api/v1/academy/tracks',
        operationId: 'listAcademyTracks',
        description: 'The learning tracks with their missions and the progress of the caller on each.',
        summary: 'List learning tracks',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Tracks', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AcademyTrackProgress'))])),
        ]
    )]
    public function tracks(AcademyLocaleRequest $request, GetAcademyTrackProgressQuery $query): JsonResponse
    {
        $progress = $query->execute($this->tenantId(), $this->userId($request), $request->academyLocale());

        return $this->successResponse($progress['tracks']);
    }

    #[OA\Get(
        path: '/api/v1/academy/tracks/{trackId}',
        operationId: 'getAcademyTrack',
        summary: 'Learning track with the progress of the caller',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'trackId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Track', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyTrackProgress')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function track(AcademyLocaleRequest $request, string $trackId, GetAcademyTrackProgressQuery $query): JsonResponse
    {
        $progress = $query->execute($this->tenantId(), $this->userId($request), $request->academyLocale());

        foreach ($progress['tracks'] as $track) {
            if ($track['id'] === $trackId) {
                return $this->successResponse($track);
            }
        }

        return $this->problemDetailsResponse('Not found', 'Learning track not found.', Response::HTTP_NOT_FOUND);
    }

    #[OA\Get(
        path: '/api/v1/academy/progress',
        operationId: 'getAcademyProgress',
        description: 'Total XP, badges and the progress on every track of the caller.',
        summary: 'Own Academy progress',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Progress', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyProgress')])),
        ]
    )]
    public function progress(AcademyLocaleRequest $request, GetAcademyTrackProgressQuery $query): JsonResponse
    {
        return $this->successResponse(new AcademyArrayResource($query->execute($this->tenantId(), $this->userId($request), $request->academyLocale())));
    }

    #[OA\Post(
        path: '/api/v1/academy/missions/{missionId}/start',
        operationId: 'startAcademyMission',
        description: 'Starts a mission. Missions that work on ERP data need a Demo / Sandbox tenant (422 otherwise).',
        summary: 'Start a mission',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'missionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Mission progress', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyMissionProgress')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Sandbox tenant required', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function startMission(AcademyLocaleRequest $request, string $missionId, StartAcademyMissionCommand $command): JsonResponse
    {
        $progress = $command->execute($this->user($request), $this->tenantId(), $missionId);

        return $this->successResponse(new MissionProgressResource($progress), 'Mission started', Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/academy/missions/{missionId}/verify-step',
        operationId: 'verifyAcademyMissionStep',
        description: 'Reports whether the mission has been completed by the activity in the sandbox. Completion is decided only by the ERP domain events evaluated server side; this call never trusts client data.',
        summary: 'Check the completion of a mission',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'missionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Mission progress', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyMissionProgress')])),
            new OA\Response(response: 404, description: 'Mission not started', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verifyStep(AcademyLocaleRequest $request, string $missionId): JsonResponse
    {
        InteractiveMission::query()->findOrFail($missionId);

        $progress = UserAcademyProgress::query()
            ->where('tenant_id', $this->tenantId())
            ->where('user_id', $this->userId($request))
            ->where('mission_id', $missionId)
            ->firstOrFail();

        return $this->successResponse(new MissionProgressResource($progress));
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function user(AcademyLocaleRequest|ContextualHelpRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function userId(AcademyLocaleRequest $request): string
    {
        return (string) $this->user($request)->id;
    }
}
