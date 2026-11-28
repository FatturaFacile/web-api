<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\SkiOutdoorSchool\Application\Handlers\InstructorHandler;
use Modules\SkiOutdoorSchool\Application\Queries\SchoolQueries;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\SaveAvailabilityRequest;
use Modules\SkiOutdoorSchool\Http\Requests\SaveInstructorRequest;
use Modules\SkiOutdoorSchool\Http\Resources\AvailabilityResource;
use Modules\SkiOutdoorSchool\Http\Resources\InstructorResource;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorAvailability;
use OpenApi\Attributes as OA;

/**
 * The instructor roster: profile, licence, languages, disciplines, rate and retention rule, availability windows and blackouts.
 */
final class InstructorController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/ski-school/instructors', operationId: 'skiSchoolInstructorsIndex', summary: 'Instructors (filter by discipline, language, activity, search)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Instructors')])]
    public function index(SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', Instructor::class);

        return $this->paged($queries->instructors($this->filters(), $this->perPage()), InstructorResource::class);
    }

    #[OA\Post(path: '/api/v1/ski-school/instructors', operationId: 'skiSchoolInstructorsStore', summary: 'Register an instructor', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Instructor')])]
    public function store(SaveInstructorRequest $request, InstructorHandler $handler): JsonResponse
    {
        Gate::authorize('create', Instructor::class);

        return $this->createdResponse(new InstructorResource($handler->save($request->validated())), $this->message('instructor_saved'));
    }

    #[OA\Get(path: '/api/v1/ski-school/instructors/{id}', operationId: 'skiSchoolInstructorsShow', summary: 'One instructor with the upcoming availability', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Instructor')])]
    public function show(string $id, SchoolQueries $queries): JsonResponse
    {
        $instructor = $queries->instructor($id);
        Gate::authorize('view', $instructor);

        return $this->successResponse(new InstructorResource($instructor), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/ski-school/instructors/{id}', operationId: 'skiSchoolInstructorsUpdate', summary: 'Change an instructor (rate, retention rule, regime, languages, disciplines)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Instructor')])]
    public function update(SaveInstructorRequest $request, string $id, InstructorHandler $handler): JsonResponse
    {
        Gate::authorize('update', Instructor::query()->findOrFail($id));

        return $this->successResponse(new InstructorResource($handler->save($request->validated(), $id)), $this->message('instructor_saved'));
    }

    #[OA\Delete(path: '/api/v1/ski-school/instructors/{id}', operationId: 'skiSchoolInstructorsDestroy', summary: 'Delete an instructor without open lessons or courses', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id, InstructorHandler $handler): JsonResponse
    {
        Gate::authorize('delete', Instructor::query()->findOrFail($id));
        $handler->delete($id);

        return $this->successResponse(null, $this->message('instructor_deleted'));
    }

    #[OA\Post(path: '/api/v1/ski-school/instructors/{id}/availability', operationId: 'skiSchoolInstructorsAvailabilityStore', summary: 'Add an availability window or a blackout', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Availability')])]
    public function addAvailability(SaveAvailabilityRequest $request, string $id, InstructorHandler $handler): JsonResponse
    {
        Gate::authorize('update', Instructor::query()->findOrFail($id));

        return $this->createdResponse(new AvailabilityResource($handler->addAvailability($id, $request->validated())), $this->message('availability_saved'));
    }

    #[OA\Delete(path: '/api/v1/ski-school/availability/{id}', operationId: 'skiSchoolAvailabilityDestroy', summary: 'Remove an availability window or a blackout', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function removeAvailability(string $id, InstructorHandler $handler): JsonResponse
    {
        Gate::authorize('delete', InstructorAvailability::query()->findOrFail($id));
        $handler->removeAvailability($id);

        return $this->successResponse(null, $this->message('availability_removed'));
    }
}
