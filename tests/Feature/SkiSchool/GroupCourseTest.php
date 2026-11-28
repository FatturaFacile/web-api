<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\SkiOutdoorSchool\Models\CourseAttendance;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\CourseTransfer;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;

require_once __DIR__.'/SkiSchoolSupport.php';

uses(RefreshDatabase::class);

test('the standard level taxonomies of Swiss Snow League and FISI AMSI are available with their order and colour', function (): void {
    skSetUp($this);

    $levels = $this->getJson(SK_API.'/reference')->assertOk()->json('data.levels');

    expect(collect($levels)->where('system', 'SNOW_LEAGUE')->pluck('code')->all())->toBe(['SNOWLI', 'BLUE_PRINCE', 'BLUE_KING', 'BLUE_STAR', 'RED_PRINCE', 'RED_KING', 'RED_STAR', 'BLACK_PRINCE', 'BLACK_KING', 'BLACK_STAR'])
        ->and(collect($levels)->where('system', 'FISI_AMSI')->pluck('code')->all())->toBe(['BRONZE', 'SILVER', 'GOLD'])
        ->and(collect($levels)->firstWhere('code', 'GOLD')['label_key'])->toBe('skiSchool.level.FISI_AMSI.GOLD');
});

test('a weekly group course is created for one level with the exempt VAT regime and a default period', function (): void {
    skSetUp($this);

    $this->postJson(SK_API.'/courses', [
        'discipline' => 'snowboard', 'level_id' => skLevel('FISI_AMSI', 'SILVER')->id, 'language_code' => 'it', 'week_start' => '2027-01-18',
        'daily_start_time' => '14:00', 'capacity' => 8,
    ])
        ->assertCreated()
        ->assertJsonPath('data.language_code', 'IT')
        ->assertJsonPath('data.end_date', '2027-01-23')
        ->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.vat_regime', 'IT_EXEMPT_ART10_N20')
        ->assertJsonPath('data.price_net', '360.00')
        ->assertJsonPath('data.level.code', 'SILVER');
});

test('a course needs a valid level and a period of at most two weeks', function (): void {
    skSetUp($this);

    $this->postJson(SK_API.'/courses', ['discipline' => 'alpine_ski', 'level_id' => (string) Str::uuid(), 'language_code' => 'DE', 'week_start' => '2027-01-18', 'daily_start_time' => '10:00', 'capacity' => 6])
        ->assertUnprocessable()->assertJsonValidationErrors(['level_id']);
    $this->postJson(SK_API.'/courses', ['discipline' => 'alpine_ski', 'level_id' => skLevel('SNOW_LEAGUE', 'SNOWLI')->id, 'language_code' => 'DE', 'week_start' => '2027-01-18', 'end_date' => '2027-02-18', 'daily_start_time' => '10:00', 'capacity' => 6])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_period');
});

test('students are enrolled with bib numbers until the capacity is reached', function (): void {
    skSetUp($this);
    $course = skCourse($this);

    $this->postJson(SK_API.'/courses/'.$course->id.'/enrollments', skStudentPayload())->assertCreated()->assertJsonPath('data.bib_number', 1)->assertJsonPath('data.price_net', '250.00')->assertJsonPath('data.status', 'ENROLLED');
    $this->postJson(SK_API.'/courses/'.$course->id.'/enrollments', skStudentPayload(['student_name' => 'Sara']))->assertCreated()->assertJsonPath('data.bib_number', 2);
    $this->postJson(SK_API.'/courses/'.$course->id.'/enrollments', skStudentPayload(['student_name' => 'Third']))->assertUnprocessable()->assertJsonPath('code', 'course_full');
    $this->postJson(SK_API.'/courses/'.$course->id.'/enrollments', ['student_name' => 'No contact'])->assertUnprocessable()->assertJsonValidationErrors(['emergency_contact_name', 'emergency_contact_phone']);
});

test('a withdrawn student frees a place and the capacity cannot drop below the enrolled students', function (): void {
    skSetUp($this);
    $course = skCourse($this);
    $first = skEnroll($this, $course);
    skEnroll($this, $course, ['student_name' => 'Sara']);

    $this->putJson(SK_API.'/courses/'.$course->id, [
        'discipline' => 'alpine_ski', 'level_id' => $course->level_id, 'language_code' => 'DE', 'week_start' => '2027-01-18', 'end_date' => '2027-01-22', 'daily_start_time' => '10:00', 'capacity' => 1,
    ])->assertUnprocessable()->assertJsonPath('code', 'capacity_below_enrolled');

    $this->deleteJson(SK_API.'/enrollments/'.$first)->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
    skEnroll($this, $course, ['student_name' => 'Replacement']);
    expect(CourseEnrollment::query()->where('status', 'ENROLLED')->count())->toBe(2);
});

test('after the Monday evaluation students move to another level group keeping the attendance history and the bib is reassigned', function (): void {
    skSetUp($this);
    $blue = skCourse($this);
    $red = skCourse($this, ['level_id' => skLevel('SNOW_LEAGUE', 'RED_PRINCE')->id, 'capacity' => 3]);
    $luca = skEnroll($this, $blue);
    $sara = skEnroll($this, $blue, ['student_name' => 'Sara']);
    skEnroll($this, $red, ['student_name' => 'Already red']);

    $this->postJson(SK_API.'/courses/'.$blue->id.'/attendance', ['attendance_date' => '2027-01-18', 'entries' => [['enrollment_id' => $luca, 'present' => true], ['enrollment_id' => $sara, 'present' => false]]])
        ->assertOk()->assertJsonPath('data.saved', 2);

    $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $luca, 'to_class_id' => $red->id]], 'reason' => 'Monday evaluation'])
        ->assertOk()->assertJsonPath('data.0.class_id', $red->id)->assertJsonPath('data.0.bib_number', 2);

    $enrollment = CourseEnrollment::query()->findOrFail($luca);
    expect((string) $enrollment->class_id)->toBe((string) $red->id)->and((string) $enrollment->initial_class_id)->toBe((string) $blue->id)
        ->and(CourseAttendance::query()->where('enrollment_id', $luca)->count())->toBe(1)
        ->and((string) CourseAttendance::query()->where('enrollment_id', $luca)->value('class_id'))->toBe((string) $blue->id)
        ->and(CourseTransfer::query()->where('enrollment_id', $luca)->value('reason'))->toBe('Monday evaluation');

    $this->getJson(SK_API.'/courses/'.$red->id)->assertOk()->assertJsonCount(2, 'data.enrollments');
    $this->getJson(SK_API.'/level-board?week_start=2027-01-18')->assertOk()->assertJsonCount(2, 'data.groups')->assertJsonPath('data.groups.0.level.code', 'BLUE_PRINCE')->assertJsonPath('data.groups.1.enrolled', 2);
});

test('a batch that overflows a group is refused as a whole and nothing moves', function (): void {
    skSetUp($this);
    $blue = skCourse($this, ['capacity' => 3]);
    $red = skCourse($this, ['level_id' => skLevel('SNOW_LEAGUE', 'RED_PRINCE')->id, 'capacity' => 1]);
    $one = skEnroll($this, $blue);
    $two = skEnroll($this, $blue, ['student_name' => 'Sara']);

    $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $one, 'to_class_id' => $red->id], ['enrollment_id' => $two, 'to_class_id' => $red->id]]])
        ->assertUnprocessable()->assertJsonPath('code', 'capacity_exceeded');

    expect(CourseEnrollment::query()->where('class_id', $blue->id)->count())->toBe(2)->and(CourseTransfer::query()->count())->toBe(0);
});

test('two full groups can swap students in one batch', function (): void {
    skSetUp($this);
    $blue = skCourse($this, ['capacity' => 1]);
    $red = skCourse($this, ['level_id' => skLevel('SNOW_LEAGUE', 'RED_PRINCE')->id, 'capacity' => 1]);
    $one = skEnroll($this, $blue);
    $two = skEnroll($this, $red, ['student_name' => 'Sara']);

    $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $one, 'to_class_id' => $red->id], ['enrollment_id' => $two, 'to_class_id' => $blue->id]]])->assertOk()->assertJsonCount(2, 'data');

    expect((string) CourseEnrollment::query()->findOrFail($one)->class_id)->toBe((string) $red->id)->and((string) CourseEnrollment::query()->findOrFail($two)->class_id)->toBe((string) $blue->id);
});

test('students cannot move to a group of another discipline or week, to a closed group or to the same group', function (): void {
    skSetUp($this);
    $blue = skCourse($this);
    $board = skCourse($this, ['discipline' => 'snowboard', 'level_id' => skLevel('SNOW_LEAGUE', 'RED_PRINCE')->id]);
    $nextWeek = skCourse($this, ['week_start' => '2027-01-25', 'end_date' => '2027-01-29', 'level_id' => skLevel('SNOW_LEAGUE', 'RED_KING')->id]);
    $student = skEnroll($this, $blue);

    foreach ([$board, $nextWeek] as $other) {
        $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $student, 'to_class_id' => $other->id]]])->assertUnprocessable()->assertJsonPath('code', 'group_mismatch');
    }

    $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $student, 'to_class_id' => $blue->id]]])->assertUnprocessable()->assertJsonPath('code', 'same_group');

    $closed = skCourse($this, ['level_id' => skLevel('SNOW_LEAGUE', 'RED_STAR')->id]);
    $this->postJson(SK_API.'/courses/'.$closed->id.'/status', ['status' => 'CANCELLED'])->assertOk();
    $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $student, 'to_class_id' => $closed->id]]])->assertUnprocessable()->assertJsonPath('code', 'group_closed');
});

test('a group of another company is not a valid destination', function (): void {
    skSetUp($this);
    $blue = skCourse($this);
    $student = skEnroll($this, $blue);
    $foreign = treasuryInTenant($this->otherTenant, fn () => GroupCourse::query()->create([
        'tenant_id' => $this->otherTenant->id, 'code' => 'X', 'discipline' => 'alpine_ski', 'level_id' => skLevel('SNOW_LEAGUE', 'SNOWLI')->id, 'language_code' => 'IT',
        'week_start' => '2027-01-18', 'end_date' => '2027-01-22', 'daily_start_time' => '10:00',
    ]));

    $this->postJson(SK_API.'/students/transfer', ['moves' => [['enrollment_id' => $student, 'to_class_id' => $foreign->id]]])->assertUnprocessable()->assertJsonValidationErrors(['moves.0.to_class_id']);
});

test('the attendance is recorded per day inside the course period only', function (): void {
    skSetUp($this);
    $course = skCourse($this);
    $student = skEnroll($this, $course);

    $this->postJson(SK_API.'/courses/'.$course->id.'/attendance', ['attendance_date' => '2027-01-19', 'entries' => [['enrollment_id' => $student, 'present' => true]]])->assertOk();
    $this->postJson(SK_API.'/courses/'.$course->id.'/attendance', ['attendance_date' => '2027-01-19', 'entries' => [['enrollment_id' => $student, 'present' => false]]])->assertOk();
    $this->postJson(SK_API.'/courses/'.$course->id.'/attendance', ['attendance_date' => '2027-02-10', 'entries' => [['enrollment_id' => $student, 'present' => true]]])->assertUnprocessable()->assertJsonPath('code', 'date_outside_course');

    expect(CourseAttendance::query()->count())->toBe(1)->and(CourseAttendance::query()->first()->present)->toBeFalse();
});

test('the bibs show the student, the level, the meeting point and the emergency contact and can be printed as PDF', function (): void {
    skSetUp($this);
    $course = skCourse($this, ['meeting_point' => 'Cervinia, Plateau Rosa kiosk']);
    skEnroll($this, $course, ['student_name' => 'Luca Bianchi']);

    $html = $this->get(SK_API.'/courses/'.$course->id.'/bibs?format=html')->assertOk()->getContent();
    expect($html)->toContain('Luca Bianchi')->toContain('Blue Prince')->toContain('Cervinia, Plateau Rosa kiosk')->toContain('Maria Bianchi')->toContain('+393331112222');

    $pdf = $this->get(SK_API.'/courses/'.$course->id.'/bibs')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

test('completing a course records the fee of its instructor for every day of the course', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    $course = skCourse($this, ['instructor_id' => $anna->id]);

    $this->postJson(SK_API.'/courses/'.$course->id.'/status', ['status' => 'COMPLETED'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    $this->postJson(SK_API.'/courses/'.$course->id.'/status', ['status' => 'OPEN'])->assertUnprocessable()->assertJsonPath('code', 'course_locked');

    $commission = InstructorCommission::query()->sole();
    expect($commission->hours)->toBe('10.00')->and($commission->gross_fee)->toBe('400.00')->and($commission->retention_amount)->toBe('40.00')->and($commission->net_fee)->toBe('360.00')
        ->and($commission->source_type->value)->toBe('COURSE');
});

test('an instructor cannot teach two courses at the same hour', function (): void {
    skSetUp($this);
    $anna = skInstructor($this);
    skCourse($this, ['instructor_id' => $anna->id]);

    $this->postJson(SK_API.'/courses', [
        'discipline' => 'alpine_ski', 'level_id' => skLevel('SNOW_LEAGUE', 'RED_KING')->id, 'language_code' => 'IT', 'week_start' => '2027-01-18', 'end_date' => '2027-01-22',
        'daily_start_time' => '11:00', 'capacity' => 6, 'instructor_id' => $anna->id,
    ])->assertUnprocessable()->assertJsonPath('code', 'instructor_course_conflict');

    $this->postJson(SK_API.'/lessons', skLessonPayload(['instructor_id' => $anna->id, 'auto_assign' => false, 'lesson_date' => '2027-01-20', 'start_time' => '10:30']))
        ->assertUnprocessable()->assertJsonPath('code', 'instructor_conflict');
});
