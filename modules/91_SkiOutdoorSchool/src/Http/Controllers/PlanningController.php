<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\SkiOutdoorSchool\Application\Queries\PlanningQueries;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\BoardRequest;
use Modules\SkiOutdoorSchool\Http\Requests\RotationRequest;
use Modules\SkiOutdoorSchool\Models\SkiLesson;
use OpenApi\Attributes as OA;

/**
 * The high-density multi-instructor planning board and the fair rotation ranking of a slot.
 */
final class PlanningController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/ski-school/planning/board', operationId: 'skiSchoolPlanningBoard', summary: 'Planning board of a day: 30 / 55 minute slots per instructor, lessons, course sessions, blackouts, unassigned lessons; filters by discipline, language, availability and requested instructor', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Board')])]
    public function board(BoardRequest $request, PlanningQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SkiLesson::class);
        $filters = $request->validated();

        return $this->successResponse($queries->board((string) ($filters['date'] ?? now()->toDateString()), $filters), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/ski-school/planning/rotation', operationId: 'skiSchoolPlanningRotation', summary: 'Fair rotation ranking of the instructors for a slot (lowest cumulative hours first; a requested instructor overrides)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Ranking')])]
    public function rotation(RotationRequest $request, PlanningQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SkiLesson::class);

        return $this->successResponse($queries->rotation($request->validated()), $this->message('loaded'));
    }
}
