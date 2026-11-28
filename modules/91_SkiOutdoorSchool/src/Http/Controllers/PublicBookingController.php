<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\SkiOutdoorSchool\Application\Handlers\PublicBookingHandler;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\PublicAvailabilityRequest;
use Modules\SkiOutdoorSchool\Http\Requests\PublicBookLessonRequest;
use Modules\SkiOutdoorSchool\Http\Requests\PublicCourseOfferRequest;
use Modules\SkiOutdoorSchool\Http\Requests\PublicEnrollRequest;
use OpenApi\Attributes as OA;

/**
 * Public, tenant-scoped booking engine of the school: no sign-in, the slug of the school selects the company (see ResolvePublicSchool),
 * the requests are throttled by IP and the client never sends a price.
 */
final class PublicBookingController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    #[OA\Get(path: '/api/v1/ski-school/public/{slug}/catalogue', operationId: 'skiSchoolPublicCatalogue', summary: 'Disciplines, languages, levels, slot lengths and add-on prices of the school', tags: ['Ski school public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Catalogue'), new OA\Response(response: 404, description: 'Unknown school')])]
    public function catalogue(string $slug, PublicBookingHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->catalogue(), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/ski-school/public/{slug}/availability', operationId: 'skiSchoolPublicAvailability', summary: 'Free start times of a day and the instructors that can be requested', tags: ['Ski school public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Availability')])]
    public function availability(string $slug, PublicAvailabilityRequest $request, PublicBookingHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->availability($request->validated()), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/ski-school/public/{slug}/courses', operationId: 'skiSchoolPublicCourses', summary: 'Weekly group courses with a free place', tags: ['Ski school public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Courses')])]
    public function courses(string $slug, PublicCourseOfferRequest $request, PublicBookingHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->courseOffer($request->validated()), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/ski-school/public/{slug}/lessons', operationId: 'skiSchoolPublicBookLesson', summary: 'Book a private lesson (optional requested instructor, rental, ARTVA kit and skipass add-ons)', tags: ['Ski school public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'Booking confirmation'), new OA\Response(response: 422, description: 'No instructor available')])]
    public function bookLesson(string $slug, PublicBookLessonRequest $request, PublicBookingHandler $handler): JsonResponse
    {
        return $this->createdResponse($handler->bookLesson($request->validated()), $this->message('lesson_booked'));
    }

    #[OA\Post(path: '/api/v1/ski-school/public/{slug}/courses/{courseId}/enrollments', operationId: 'skiSchoolPublicEnroll', summary: 'Book a place in a weekly group course', tags: ['Ski school public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'courseId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Booking confirmation'), new OA\Response(response: 422, description: 'Course full or closed')])]
    public function enroll(string $slug, string $courseId, PublicEnrollRequest $request, PublicBookingHandler $handler): JsonResponse
    {
        return $this->createdResponse($handler->enroll($request->validated() + ['course_id' => $courseId]), $this->message('student_enrolled'));
    }
}
