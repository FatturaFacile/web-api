<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Enums\CourseKind;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\ResourceBooking;
use Modules\Associations\Models\TicketType;
use Modules\Associations\Services\CourseCalendar;
use Modules\GeneralLedger\Models\CostCenter;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
});

afterEach(fn () => associationsTearDown());

/**
 * A course meeting every day of the last month and the coming months.
 */
function dailyCourse(array $attributes = []): Course
{
    $course = Course::factory()->create($attributes);
    $course->schedules()->create(['pattern' => 'daily', 'starts_at' => '18:00', 'ends_at' => '19:00']);

    return $course;
}

function enrol(object $test, Course $course, ?Member $member = null): TestResponse
{
    return $test->postJson("/api/v1/associations/courses/{$course->id}/enrolments", ['member_id' => ($member ?? Member::factory()->create())->id]);
}

test('a course keeps discipline, department, room and an existing cost centre', function (): void {
    $discipline = Discipline::query()->create(['code' => 'NUO', 'name' => 'Swimming']);
    $costCenter = CostCenter::query()->create(['code' => 'YOUTH', 'name' => 'Youth sector']);
    $foreignCostCenter = inTenant($this->otherTenant, fn () => CostCenter::query()->create(['code' => 'X', 'name' => 'Other']));
    $resource = BookableResource::factory()->create();

    $this->postJson('/api/v1/associations/courses', ['title' => 'Bad', 'starts_on' => '2026-01-01', 'cost_center_id' => $foreignCostCenter->id])->assertUnprocessable();

    $id = $this->postJson('/api/v1/associations/courses', [
        'title' => 'Swimming', 'starts_on' => '2026-01-01', 'discipline_id' => $discipline->id, 'cost_center_id' => $costCenter->id, 'resource_id' => $resource->id,
    ])->assertCreated()->json('data.id');

    $course = Course::query()->findOrFail($id);

    expect($course->costCenter->code)->toBe('YOUTH')
        ->and($this->getJson("/api/v1/associations/courses/{$id}")->json('data.discipline.code'))->toBe('NUO');
});

test('the calendar follows daily, weekday and date schedules and shows a suspension without cancelling the course', function (): void {
    $course = Course::factory()->create(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);
    $course->schedules()->create(['pattern' => 'weekday', 'weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '19:00']);
    $course->schedules()->create(['pattern' => 'date', 'on_date' => '2026-03-14', 'starts_at' => '10:00', 'ends_at' => '11:00']);
    $this->postJson("/api/v1/associations/courses/{$course->id}/suspensions", ['starts_on' => '2026-03-09', 'ends_on' => '2026-03-15', 'reason' => 'Works'])->assertCreated();

    $lessons = collect(app(CourseCalendar::class)->occurrences($course, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')));

    expect($lessons->pluck('date')->all())->toBe(['2026-03-02', '2026-03-09', '2026-03-14', '2026-03-16', '2026-03-23', '2026-03-30'])
        ->and($lessons->where('suspended', true)->pluck('date')->values()->all())->toBe(['2026-03-09', '2026-03-14'])
        ->and($course->refresh()->status)->toBe(Course::Active);
});

test('capacity closes the course automatically, the waiting list moves up and the course opens again', function (): void {
    $course = dailyCourse(['capacity' => 2]);
    [$first, $second, $waiting] = Member::factory()->count(3)->create();

    enrol($this, $course, $first)->assertCreated()->assertJsonPath('data.status', 'enrolled');
    enrol($this, $course, $second)->assertCreated();

    expect($course->refresh()->registrations_open)->toBeFalse();

    enrol($this, $course, $waiting)->assertCreated()->assertJsonPath('data.status', 'waitlisted')->assertJsonPath('data.waitlist_position', 1);

    $enrolment = CourseEnrolment::query()->where('member_id', $first->id)->firstOrFail();
    $this->postJson("/api/v1/associations/course-enrolments/{$enrolment->id}/withdraw")->assertOk();

    expect(CourseEnrolment::query()->where('member_id', $waiting->id)->firstOrFail()->status)->toBe(CourseEnrolmentStatus::Enrolled)
        ->and($course->refresh()->registrations_open)->toBeFalse();

    $another = CourseEnrolment::query()->where('member_id', $second->id)->firstOrFail();
    $this->postJson("/api/v1/associations/course-enrolments/{$another->id}/withdraw")->assertOk();

    expect($course->refresh()->registrations_open)->toBeTrue();
});

test('without a waiting list a full course refuses, and a closed course stays closed', function (): void {
    $full = dailyCourse(['capacity' => 1, 'waitlist_enabled' => false]);
    enrol($this, $full)->assertCreated();
    enrol($this, $full)->assertConflict();

    enrol($this, dailyCourse(['registrations_open' => false]))->assertConflict();
});

test('a person is enrolled once', function (): void {
    $course = dailyCourse();
    $member = Member::factory()->create();

    enrol($this, $course, $member)->assertCreated();
    enrol($this, $course, $member)->assertConflict();
});

test('the fee of the course is assigned on enrolment, and only when a place is obtained', function (): void {
    $plan = FeePlan::factory()->create();
    $course = dailyCourse(['capacity' => 1, 'fee_plan_id' => $plan->id]);
    [$first, $waiting] = Member::factory()->count(2)->create();

    enrol($this, $course, $first)->assertCreated();
    enrol($this, $course, $waiting)->assertCreated();

    expect(FeeAssignment::query()->where('member_id', $first->id)->count())->toBe(1)
        ->and(FeeAssignment::query()->where('member_id', $waiting->id)->count())->toBe(0);
});

test('attendance takes lessons from a package, once a day, and gives them back', function (): void {
    $plan = FeePlan::factory()->create(['frequency' => FeeFrequency::PerAttendance, 'amount' => '100.00', 'lessons_included' => 2]);
    $course = dailyCourse(['fee_plan_id' => $plan->id]);
    $member = Member::factory()->create();
    enrol($this, $course, $member)->assertCreated();
    $assignment = FeeAssignment::query()->where('member_id', $member->id)->firstOrFail();
    $today = now()->toDateString();
    $mark = fn (string $date, string $status) => $this->postJson("/api/v1/associations/courses/{$course->id}/attendance", ['date' => $date, 'entries' => [['member_id' => $member->id, 'status' => $status]]]);

    expect($assignment->instalments)->toHaveCount(1)->and($assignment->lessons_remaining)->toBe(2);

    $mark($today, 'present')->assertOk();
    $mark($today, 'present')->assertOk();
    expect($assignment->refresh()->lessons_remaining)->toBe(1);

    $mark($today, 'absent')->assertOk();
    expect($assignment->refresh()->lessons_remaining)->toBe(2);

    $mark($today, 'present')->assertOk();
    $mark(now()->subDay()->toDateString(), 'present')->assertOk()->assertJsonPath('data.0.package_exhausted', false);
    $mark(now()->subDays(2)->toDateString(), 'present')->assertOk()->assertJsonPath('data.0.package_exhausted', true);

    expect($assignment->refresh()->lessons_remaining)->toBe(0);
});

test('a fee per attendance charges each presence and withdraws the charge if undone', function (): void {
    $plan = FeePlan::factory()->create(['frequency' => FeeFrequency::PerAttendance, 'amount' => '12.50']);
    $course = dailyCourse(['fee_plan_id' => $plan->id]);
    $member = Member::factory()->create();
    enrol($this, $course, $member)->assertCreated();
    $assignment = FeeAssignment::query()->where('member_id', $member->id)->firstOrFail();
    $mark = fn (string $status) => $this->postJson("/api/v1/associations/courses/{$course->id}/attendance", ['date' => now()->toDateString(), 'entries' => [['member_id' => $member->id, 'status' => $status]]]);

    expect($assignment->instalments)->toHaveCount(0);

    $mark('present')->assertOk();
    expect((string) $assignment->refresh()->net_amount)->toBe('12.50');

    $mark('absent')->assertOk();

    expect((string) $assignment->refresh()->net_amount)->toBe('0.00')
        ->and($assignment->instalments()->firstOrFail()->status)->toBe(InstalmentStatus::Cancelled);
});

test('attendance is refused for a suspended day, a day without lesson, the future and strangers', function (): void {
    $course = Course::factory()->create();
    $course->schedules()->create(['pattern' => 'weekday', 'weekday' => now()->dayOfWeekIso, 'starts_at' => '18:00', 'ends_at' => '19:00']);
    $member = Member::factory()->create();
    enrol($this, $course, $member)->assertCreated();
    $mark = fn (string $date, ?Member $who = null) => $this->postJson("/api/v1/associations/courses/{$course->id}/attendance", ['date' => $date, 'entries' => [['member_id' => ($who ?? $member)->id, 'status' => 'present']]]);

    $mark(now()->subDay()->toDateString())->assertUnprocessable();
    $mark(now()->addWeek()->toDateString())->assertUnprocessable();
    $mark(now()->toDateString(), Member::factory()->create())->assertUnprocessable();

    $course->suspensions()->create(['starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString()]);
    $mark(now()->toDateString())->assertUnprocessable();

    expect(Attendance::query()->count())->toBe(0);
});

test('a course that requires a certificate blocks who has none, or an expired one', function (): void {
    $discipline = Discipline::query()->create(['code' => 'CAL', 'name' => 'Football']);
    $competition = dailyCourse(['required_certificate' => CertificateKind::Competitive, 'discipline_id' => $discipline->id]);
    $member = Member::factory()->create();

    enrol($this, $competition, $member)->assertUnprocessable();

    MedicalCertificate::factory()->create(['member_id' => $member->id, 'kind' => CertificateKind::NonCompetitive, 'discipline_id' => $discipline->id]);
    enrol($this, $competition, $member)->assertUnprocessable();

    MedicalCertificate::factory()->create(['member_id' => $member->id, 'kind' => CertificateKind::Competitive, 'discipline_id' => $discipline->id, 'expires_on' => now()->subDay()]);
    enrol($this, $competition, $member)->assertUnprocessable();

    MedicalCertificate::factory()->create(['member_id' => $member->id, 'kind' => CertificateKind::Competitive, 'discipline_id' => $discipline->id]);
    enrol($this, $competition, $member)->assertCreated();
});

test('a big event sells tickets with limits, a waiting list and a single check-in', function (): void {
    $event = Course::factory()->create(['kind' => CourseKind::BigEvent, 'capacity' => 100]);
    $ticketId = $this->postJson("/api/v1/associations/courses/{$event->id}/ticket-types", ['name' => 'Standard', 'price' => '15.00', 'quantity' => 1])->assertCreated()->json('data.id');
    [$first, $second] = Member::factory()->count(2)->create();
    $register = fn (Member $member) => $this->postJson("/api/v1/associations/ticket-types/{$ticketId}/registrations", ['member_id' => $member->id]);

    $code = $register($first)->assertCreated()->assertJsonPath('data.status', 'confirmed')->json('data.ticket_code');
    $register($second)->assertCreated()->assertJsonPath('data.status', 'waitlisted');

    $assignment = FeeAssignment::query()->where('member_id', $first->id)->firstOrFail();

    expect((string) $assignment->net_amount)->toBe('15.00')
        ->and($assignment->course_id)->toBe($event->id)
        ->and(FeeAssignment::query()->where('member_id', $second->id)->exists())->toBeFalse();

    $this->postJson("/api/v1/associations/courses/{$event->id}/check-in", ['ticket_code' => $code])->assertOk();
    $this->postJson("/api/v1/associations/courses/{$event->id}/check-in", ['ticket_code' => $code])->assertConflict();

    $registration = collect($this->getJson("/api/v1/associations/courses/{$event->id}/registrations")->json('data'))->firstWhere('member_id', $first->id);
    $this->postJson("/api/v1/associations/event-registrations/{$registration['id']}/cancel")->assertOk();

    expect(collect($this->getJson("/api/v1/associations/courses/{$event->id}/registrations")->json('data'))->firstWhere('member_id', $second->id)['status'])->toBe('confirmed');
});

test('tickets are not sold for a recurring course', function (): void {
    $course = dailyCourse();
    $ticket = TicketType::query()->create(['course_id' => $course->id, 'name' => 'X']);

    $this->postJson("/api/v1/associations/ticket-types/{$ticket->id}/registrations", ['member_id' => Member::factory()->create()->id])->assertConflict();
});

test('overlapping bookings of a resource are refused, adjacent ones and other resources are not', function (): void {
    $court = BookableResource::factory()->create();
    $otherCourt = BookableResource::factory()->create();
    $book = fn (BookableResource $resource, string $from, string $to) => $this->postJson('/api/v1/associations/bookings', ['resource_id' => $resource->id, 'starts_at' => $from, 'ends_at' => $to]);

    $id = $book($court, '2026-06-01 10:00:00', '2026-06-01 11:00:00')->assertCreated()->json('data.id');
    $book($court, '2026-06-01 10:30:00', '2026-06-01 11:30:00')->assertConflict();
    $book($court, '2026-06-01 09:00:00', '2026-06-01 12:00:00')->assertConflict();
    $book($court, '2026-06-01 11:00:00', '2026-06-01 12:00:00')->assertCreated();
    $book($otherCourt, '2026-06-01 10:30:00', '2026-06-01 11:30:00')->assertCreated();

    $this->postJson("/api/v1/associations/bookings/{$id}/cancel")->assertOk();
    $book($court, '2026-06-01 10:30:00', '2026-06-01 11:00:00')->assertCreated();
});

test('booking the lessons of a course is all or nothing', function (): void {
    $court = BookableResource::factory()->create();
    $course = Course::factory()->create(['resource_id' => $court->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30']);
    $course->schedules()->create(['pattern' => 'weekday', 'weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '19:00']);
    ResourceBooking::query()->create(['resource_id' => $court->id, 'starts_at' => '2026-06-22 18:30:00', 'ends_at' => '2026-06-22 18:45:00']);

    $this->postJson("/api/v1/associations/courses/{$course->id}/book-lessons", ['from' => '2026-06-01', 'to' => '2026-06-30'])->assertConflict();

    expect(ResourceBooking::query()->count())->toBe(1);

    $this->postJson("/api/v1/associations/courses/{$course->id}/book-lessons", ['from' => '2026-06-01', 'to' => '2026-06-15'])->assertCreated()->assertJsonPath('data.bookings', 3);
});

test('a resource of another tenant cannot be booked, and bookings do not cross tenants', function (): void {
    $foreign = inTenant($this->otherTenant, fn () => BookableResource::factory()->create());

    $this->postJson('/api/v1/associations/bookings', ['resource_id' => $foreign->id, 'starts_at' => '2026-06-01 10:00:00', 'ends_at' => '2026-06-01 11:00:00'])->assertUnprocessable();
});

test('the participants list shows the fee state to whoever may see it', function (): void {
    $course = dailyCourse();
    enrol($this, $course)->assertCreated();

    expect($this->getJson("/api/v1/associations/courses/{$course->id}/participants")->json('data.0.fee_standing'))->toBe('orange');
});
