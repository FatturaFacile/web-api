<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use Database\Seeders\SkiSchoolReferenceSeeder;
use Modules\SkiOutdoorSchool\Models\CourseLevel;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

const SK_API = '/api/v1/ski-school';

/**
 * Builders of the ski school tests (Mod 286): an Italian company, a member signed in with a role, the standard level taxonomy and
 * instructors / lessons / courses created through the API. The clock stands on Monday 18 January 2027, the first day of a ski week.
 */
function skSetUp(object $test, string $role = 'accountant'): void
{
    $test->travelTo('2027-01-18 09:00:00');
    $test->tenant = Tenant::create([
        'name' => 'Scuola Sci '.strtoupper(substr(md5((string) microtime(true)), 0, 5)),
        'legal_name' => 'Scuola Sci Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'vat_number' => 'IT01234567897',
    ]);
    $test->otherTenant = Tenant::create(['name' => 'Altra Scuola', 'legal_name' => 'Altra Scuola Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    (new SkiSchoolReferenceSeeder)->run();
    $test->actor = treasuryActor($test, $role);
}

function skLevel(string $system, string $code): CourseLevel
{
    return CourseLevel::query()->where('system', $system)->where('code', $code)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function skInstructorPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Anna', 'last_name' => 'Rossi', 'license_type' => 'AMSI_FISI', 'license_number' => 'AMSI-123', 'hourly_rate' => 40,
        'retention_mode' => 'PERCENT', 'retention_value' => 10, 'collaboration_regime' => 'FREELANCE', 'withholding_percent' => 0,
        'languages' => [['code' => 'IT'], ['code' => 'EN']], 'disciplines' => ['alpine_ski', 'snowboard'],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function skInstructor(object $test, array $overrides = []): Instructor
{
    $id = (string) $test->postJson(SK_API.'/instructors', skInstructorPayload($overrides))->assertCreated()->json('data.id');

    return Instructor::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function skLessonPayload(array $overrides = []): array
{
    return array_merge([
        'discipline' => 'alpine_ski', 'language_code' => 'IT', 'lesson_date' => '2027-01-19', 'start_time' => '09:00', 'slot_minutes' => 55, 'slot_count' => 1,
        'participants' => 2, 'customer_name' => 'Famiglia Bianchi', 'customer_email' => 'bianchi@example.com', 'auto_assign' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function skLesson(object $test, array $overrides = []): SkiLesson
{
    $id = (string) $test->postJson(SK_API.'/lessons', skLessonPayload($overrides))->assertCreated()->json('data.id');

    return SkiLesson::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function skCourse(object $test, array $overrides = []): GroupCourse
{
    $id = (string) $test->postJson(SK_API.'/courses', array_merge([
        'discipline' => 'alpine_ski', 'level_id' => skLevel('SNOW_LEAGUE', 'BLUE_PRINCE')->id, 'language_code' => 'DE', 'week_start' => '2027-01-18', 'end_date' => '2027-01-22',
        'daily_start_time' => '10:00', 'duration_minutes' => 120, 'capacity' => 2, 'price_net' => 250,
    ], $overrides))->assertCreated()->json('data.id');

    return GroupCourse::query()->findOrFail($id);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function skStudentPayload(array $overrides = []): array
{
    return array_merge(['student_name' => 'Luca Bianchi', 'birth_date' => '2016-05-01', 'emergency_contact_name' => 'Maria Bianchi', 'emergency_contact_phone' => '+393331112222'], $overrides);
}

function skEnroll(object $test, GroupCourse $course, array $overrides = []): string
{
    return (string) $test->postJson(SK_API.'/courses/'.$course->id.'/enrollments', skStudentPayload($overrides))->assertCreated()->json('data.id');
}

function skCustomer(object $test): Contact
{
    return Contact::factory()->create(['tenant_id' => $test->tenant->id, 'company_name' => 'Famiglia Bianchi', 'country' => 'IT']);
}
