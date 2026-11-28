<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\SkiOutdoorSchool\Application\Commands\EnrollStudentCommand;
use Modules\SkiOutdoorSchool\Application\Handlers\BillingHandler;
use Modules\SkiOutdoorSchool\Application\Handlers\GroupCourseHandler;
use Modules\SkiOutdoorSchool\Application\Handlers\PackageHandler;
use Modules\SkiOutdoorSchool\Application\Queries\SchoolQueries;
use Modules\SkiOutdoorSchool\Application\Services\DocumentRenderer;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\AttendanceRequest;
use Modules\SkiOutdoorSchool\Http\Requests\CourseStatusRequest;
use Modules\SkiOutdoorSchool\Http\Requests\EnrollStudentRequest;
use Modules\SkiOutdoorSchool\Http\Requests\InvoiceRequest;
use Modules\SkiOutdoorSchool\Http\Requests\LevelBoardRequest;
use Modules\SkiOutdoorSchool\Http\Requests\ReplacePackageRequest;
use Modules\SkiOutdoorSchool\Http\Requests\SaveCourseRequest;
use Modules\SkiOutdoorSchool\Http\Requests\TransferStudentsRequest;
use Modules\SkiOutdoorSchool\Http\Resources\CourseResource;
use Modules\SkiOutdoorSchool\Http\Resources\EnrollmentResource;
use Modules\SkiOutdoorSchool\Http\Resources\LevelResource;
use Modules\SkiOutdoorSchool\Http\Resources\PackageItemResource;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use OpenApi\Attributes as OA;

/**
 * Weekly group courses by technical level: courses, enrolments, the level board and the batch reassignment after the Monday evaluation,
 * attendance, bibs, package and invoice of an enrolment.
 */
final class CourseController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/ski-school/courses', operationId: 'skiSchoolCoursesIndex', summary: 'Group courses (filter by week, discipline, level, status, instructor)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Courses')])]
    public function index(SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', GroupCourse::class);

        return $this->paged($queries->courses($this->filters(), $this->perPage()), CourseResource::class);
    }

    #[OA\Post(path: '/api/v1/ski-school/courses', operationId: 'skiSchoolCoursesStore', summary: 'Create a weekly group course of one level', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Course')])]
    public function store(SaveCourseRequest $request, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('create', GroupCourse::class);

        return $this->createdResponse(new CourseResource($handler->save($request->validated())), $this->message('course_saved'));
    }

    #[OA\Get(path: '/api/v1/ski-school/courses/{id}', operationId: 'skiSchoolCoursesShow', summary: 'One course with its students, attendance and transfers', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Course')])]
    public function show(string $id, SchoolQueries $queries): JsonResponse
    {
        $course = $queries->course($id);
        Gate::authorize('view', $course);

        return $this->successResponse(new CourseResource($course), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/ski-school/courses/{id}', operationId: 'skiSchoolCoursesUpdate', summary: 'Change a course', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Course')])]
    public function update(SaveCourseRequest $request, string $id, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('update', GroupCourse::query()->findOrFail($id));

        return $this->successResponse(new CourseResource($handler->save($request->validated(), $id)), $this->message('course_saved'));
    }

    #[OA\Post(path: '/api/v1/ski-school/courses/{id}/status', operationId: 'skiSchoolCoursesStatus', summary: 'Open, start, complete (records the fee of the instructor) or cancel a course', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Course')])]
    public function status(CourseStatusRequest $request, string $id, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('update', GroupCourse::query()->findOrFail($id));

        return $this->successResponse(new CourseResource($handler->setStatus($id, CourseStatus::from((string) $request->validated('status')))), $this->message('course_status_changed'));
    }

    #[OA\Get(path: '/api/v1/ski-school/level-board', operationId: 'skiSchoolLevelBoard', summary: 'The groups of a week ordered by level with their students (reassignment board)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Board')])]
    public function levelBoard(LevelBoardRequest $request, SchoolQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', GroupCourse::class);
        $board = $queries->levelBoard($request->validated());

        return $this->successResponse([
            'week_start' => $board['week_start'],
            'groups' => CourseResource::collection($board['groups']),
            'levels' => LevelResource::collection($board['levels']),
        ], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/ski-school/courses/{id}/enrollments', operationId: 'skiSchoolCoursesEnroll', summary: 'Enrol a student (capacity checked)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Enrolment')])]
    public function enroll(EnrollStudentRequest $request, string $id, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('update', GroupCourse::query()->findOrFail($id));
        $enrollment = $handler->enroll(EnrollStudentCommand::fromArray($request->validated() + ['course_id' => $id], $this->userId()));

        return $this->createdResponse(new EnrollmentResource($enrollment->load('packageItems')), $this->message('student_enrolled'));
    }

    #[OA\Post(path: '/api/v1/ski-school/students/transfer', operationId: 'skiSchoolStudentsTransfer', summary: 'Move students between level groups in one batch (capacity of every group checked, attendance kept)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Moved enrolments')])]
    public function transfer(TransferStudentsRequest $request, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('create', GroupCourse::class);
        $moved = $handler->transfer($request->validated('moves'), $request->validated('reason'), $this->userId());

        return $this->successResponse(EnrollmentResource::collection($moved), $this->message('students_transferred'));
    }

    #[OA\Post(path: '/api/v1/ski-school/courses/{id}/attendance', operationId: 'skiSchoolCoursesAttendance', summary: 'Record the attendance of a day', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Attendance')])]
    public function attendance(AttendanceRequest $request, string $id, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('update', GroupCourse::query()->findOrFail($id));
        $rows = $handler->recordAttendance($id, (string) $request->validated('attendance_date'), array_values((array) $request->validated('entries')));

        return $this->successResponse(['saved' => count($rows)], $this->message('attendance_saved'));
    }

    #[OA\Get(path: '/api/v1/ski-school/courses/{id}/bibs', operationId: 'skiSchoolCoursesBibs', summary: 'Printable bibs / passes of a course as PDF (or HTML with ?format=html)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function bibs(Request $request, string $id, SchoolQueries $queries, DocumentRenderer $renderer): Response
    {
        $course = $queries->course($id);
        Gate::authorize('view', $course);
        $locale = app()->getLocale();

        if ($request->query('format') === 'html') {
            return response($renderer->bibsHtml($course, $locale), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return response($renderer->bibsPdf($course, $locale), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="bibs-'.$course->code.'.pdf"']);
    }

    #[OA\Delete(path: '/api/v1/ski-school/enrollments/{id}', operationId: 'skiSchoolEnrollmentsWithdraw', summary: 'Withdraw a student (not once invoiced)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Enrolment')])]
    public function withdraw(string $id, GroupCourseHandler $handler): JsonResponse
    {
        Gate::authorize('delete', CourseEnrollment::query()->findOrFail($id));

        return $this->successResponse(new EnrollmentResource($handler->withdraw($id)), $this->message('student_withdrawn'));
    }

    #[OA\Put(path: '/api/v1/ski-school/enrollments/{id}/package', operationId: 'skiSchoolEnrollmentsPackage', summary: 'Replace the package items (rental, ARTVA kit, skipass) of an enrolment', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Package items')])]
    public function package(ReplacePackageRequest $request, string $id, PackageHandler $handler): JsonResponse
    {
        Gate::authorize('update', CourseEnrollment::query()->findOrFail($id));

        return $this->successResponse(PackageItemResource::collection($handler->replaceForEnrollment($id, (array) ($request->validated('items') ?? []))), $this->message('package_saved'));
    }

    #[OA\Post(path: '/api/v1/ski-school/enrollments/{id}/invoice', operationId: 'skiSchoolEnrollmentsInvoice', summary: 'Invoice an enrolment with its package (instruction exempt, add-ons taxed)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 201, description: 'Invoices')])]
    public function invoice(InvoiceRequest $request, string $id, BillingHandler $handler): JsonResponse
    {
        Gate::authorize('update', CourseEnrollment::query()->findOrFail($id));

        return $this->createdResponse($handler->invoiceEnrollment($id, $request->validated('contact_id'), $this->userId()), $this->message('invoiced'));
    }
}
