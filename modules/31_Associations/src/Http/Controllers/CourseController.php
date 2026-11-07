<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Http\Requests\AttendanceRequest;
use Modules\Associations\Http\Requests\MemberReferenceRequest;
use Modules\Associations\Http\Requests\PeriodRequest;
use Modules\Associations\Http\Requests\SaveCourseRequest;
use Modules\Associations\Http\Requests\StoreScheduleRequest;
use Modules\Associations\Http\Requests\StoreSuspensionRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\CourseSchedule;
use Modules\Associations\Models\CourseSuspension;
use Modules\Associations\Models\Member;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\AttendanceService;
use Modules\Associations\Services\BookingService;
use Modules\Associations\Services\CourseCalendar;
use Modules\Associations\Services\CourseService;
use Modules\Associations\Services\FeeStandingService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Courses and events: calendar, instructors, participants and attendance.
 */
final class CourseController extends AssociationController
{
    public function __construct(
        private readonly CourseService $courses,
        private readonly CourseCalendar $calendar,
        private readonly AttendanceService $attendance,
        private readonly FeeStandingService $standings,
        private readonly BookingService $bookings,
    ) {}

    #[AssociationGet('/api/v1/associations/courses', 'listAssociationCourses', 'List courses and events', query: ['kind', 'status', 'discipline_id', 'year_id'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Course::class);

        $courses = Course::query()
            ->when($request->filled('kind'), static fn ($query) => $query->where('kind', (string) $request->query('kind')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('discipline_id'), static fn ($query) => $query->where('discipline_id', (string) $request->query('discipline_id')))
            ->when($request->filled('year_id'), static fn ($query) => $query->where('year_id', (string) $request->query('year_id')))
            ->orderByDesc('starts_on')
            ->paginate($this->perPage($request));

        return AssociationRecordResource::collection($courses);
    }

    #[AssociationPost('/api/v1/associations/courses', 'createAssociationCourse', 'Create a course or an event', 'AssociationCourseRequest')]
    public function store(SaveCourseRequest $request): AssociationRecordResource
    {
        $this->authorize('create', Course::class);

        return new AssociationRecordResource(Course::create($request->validated()));
    }

    #[AssociationGet('/api/v1/associations/courses/{id}', 'showAssociationCourse', 'Show a course with its calendar rules, instructors and tickets')]
    public function show(string $id): AssociationRecordResource
    {
        $course = Course::query()->with(['schedules', 'suspensions', 'instructors.member.contact', 'ticketTypes', 'discipline', 'department', 'resource'])->findOrFail($id);
        $this->authorize('view', $course);

        return new AssociationRecordResource($course);
    }

    #[AssociationPut('/api/v1/associations/courses/{id}', 'updateAssociationCourse', 'Update a course or an event', 'AssociationCourseRequest')]
    public function update(SaveCourseRequest $request, string $id): AssociationRecordResource
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);
        $course->update($request->validated());

        return new AssociationRecordResource($course);
    }

    #[AssociationDelete('/api/v1/associations/courses/{id}', 'deleteAssociationCourse', 'Delete a course or an event')]
    public function destroy(string $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('delete', $course);
        $course->delete();

        return response()->json(null, 204);
    }

    #[AssociationGet('/api/v1/associations/courses/{id}/calendar', 'showAssociationCourseCalendar', 'List the lessons of a course in a period', query: ['from', 'to'])]
    public function calendar(Request $request, string $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('view', $course);
        $from = CarbonImmutable::parse((string) $request->query('from', CarbonImmutable::today()->toDateString()));
        $to = CarbonImmutable::parse((string) $request->query('to', $from->addMonth()->toDateString()))->min($from->addYear());

        return response()->json(['data' => $this->calendar->occurrences($course, $from, $to)]);
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/schedules', 'addAssociationCourseSchedule', 'Add a schedule to a course', 'AssociationScheduleRequest')]
    public function storeSchedule(StoreScheduleRequest $request, string $id): AssociationRecordResource
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new AssociationRecordResource(CourseSchedule::create([...$request->validated(), 'course_id' => $course->id]));
    }

    #[AssociationDelete('/api/v1/associations/course-schedules/{id}', 'deleteAssociationCourseSchedule', 'Remove a schedule from a course')]
    public function destroySchedule(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        CourseSchedule::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/suspensions', 'suspendAssociationCourse', 'Suspend a course between two dates without cancelling it', 'AssociationSuspensionRequest')]
    public function storeSuspension(StoreSuspensionRequest $request, string $id): AssociationRecordResource
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new AssociationRecordResource(CourseSuspension::create([...$request->validated(), 'course_id' => $course->id]));
    }

    #[AssociationDelete('/api/v1/associations/course-suspensions/{id}', 'deleteAssociationCourseSuspension', 'Remove a suspension from a course')]
    public function destroySuspension(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        CourseSuspension::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/instructors', 'assignAssociationInstructor', 'Assign an instructor to a course', 'AssociationMemberReferenceRequest')]
    public function assignInstructor(MemberReferenceRequest $request, string $id): AssociationRecordResource
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new AssociationRecordResource($this->courses->assignInstructor($course, Member::query()->findOrFail($request->validated('member_id'))));
    }

    #[AssociationDelete('/api/v1/associations/course-instructors/{id}', 'removeAssociationInstructor', 'Remove an instructor from a course')]
    public function removeInstructor(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        CourseInstructor::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationGet('/api/v1/associations/courses/{id}/participants', 'listAssociationCourseParticipants', 'List the participants with their fee state and the waiting list')]
    public function participants(string $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('view', $course);

        $enrolments = CourseEnrolment::query()->with('member.contact')->where('course_id', $course->id)
            ->where('status', '!=', CourseEnrolmentStatus::Withdrawn)
            ->orderBy('status')->orderBy('waitlist_position')->get();

        $standings = Gate::allows(AssociationCapability::Finance->value)
            ? $this->standings->ofMembers($enrolments->pluck('member_id')->all(), $course->id)
            : [];

        return response()->json(['data' => $enrolments->map(static fn (CourseEnrolment $enrolment): array => [
            'enrolment_id' => $enrolment->id,
            'member_id' => $enrolment->member_id,
            'name' => $enrolment->member->displayName(),
            'status' => $enrolment->status,
            'waitlist_position' => $enrolment->waitlist_position,
            'enrolled_on' => $enrolment->enrolled_on?->toDateString(),
            'fee_standing' => ($standings[$enrolment->member_id] ?? null)?->color(),
        ])]);
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/enrolments', 'enrolAssociationCourseParticipant', 'Enrol a person, on the waiting list when the course is full', 'AssociationMemberReferenceRequest')]
    public function enrol(MemberReferenceRequest $request, string $id): AssociationRecordResource
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new AssociationRecordResource($this->courses->enrol($course, Member::query()->findOrFail($request->validated('member_id'))));
    }

    #[AssociationPost('/api/v1/associations/course-enrolments/{id}/withdraw', 'withdrawAssociationCourseParticipant', 'Withdraw a participant; the first of the waiting list takes the place')]
    public function withdraw(string $id): AssociationRecordResource
    {
        $enrolment = CourseEnrolment::query()->findOrFail($id);
        $this->authorize('update', $enrolment);

        return new AssociationRecordResource($this->courses->withdraw($enrolment));
    }

    #[AssociationGet('/api/v1/associations/courses/{id}/attendance', 'listAssociationAttendance', 'List the attendance of a course', query: ['date'])]
    public function attendanceList(Request $request, string $id): AnonymousResourceCollection
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('view', $course);

        $records = Attendance::query()->where('course_id', $course->id)
            ->when($request->filled('date'), static fn ($query) => $query->whereDate('attended_on', (string) $request->query('date')))
            ->orderByDesc('attended_on')
            ->paginate($this->perPage($request, 100, 500));

        return AssociationRecordResource::collection($records);
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/attendance', 'recordAssociationAttendance', 'Record the attendance of a day', 'AssociationAttendanceRequest')]
    public function recordAttendance(AttendanceRequest $request, string $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);

        $result = $this->attendance->record($course, CarbonImmutable::parse($request->validated('date')), $request->validated('entries'), ['user_id' => $this->actorId($request)]);

        return response()->json(['data' => $result]);
    }

    #[AssociationPost('/api/v1/associations/courses/{id}/book-lessons', 'bookAssociationCourseLessons', 'Book the resource of a course for its lessons in a period, all or nothing', 'AssociationPeriodRequest')]
    public function bookLessons(PeriodRequest $request, string $id): JsonResponse
    {
        $course = Course::query()->findOrFail($id);
        $this->authorize('update', $course);

        $count = $this->bookings->bookCourseLessons($course, CarbonImmutable::parse($request->validated('from')), CarbonImmutable::parse($request->validated('to')));

        return response()->json(['data' => ['bookings' => $count]], 201);
    }
}
