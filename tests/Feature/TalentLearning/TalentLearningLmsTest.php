<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\TalentLearning\Models\LmsCertificate;

require_once __DIR__.'/TalentLearningSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = tlTenant('Acme');
    $this->hr = tlMember($this->tenant, 'hr');
    tlAct($this->hr, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tlCourse(array $overrides = [], bool $publish = true): array
{
    $course = test()->postJson('/api/v1/talent-learning/courses', [
        'code' => 'C-'.strtoupper(Str::random(6)),
        'title' => 'Fire safety',
        'delivery' => 'online',
        'duration_hours' => 2,
        ...$overrides,
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

    return $publish ? test()->postJson("/api/v1/talent-learning/courses/{$course['id']}/publish")->assertOk()->json('data') : $course;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tlEnrol(string $courseId, string $employeeId, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/talent-learning/enrolments', ['course_id' => $courseId, 'employee_id' => $employeeId, ...$overrides]);
}

test('learning management is for HR while every member reads the published catalogue', function (): void {
    $published = tlCourse(['title' => 'Published one']);
    $draft = tlCourse(['title' => 'Draft one'], false);

    tlAct(tlMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/courses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published['id']);
    $this->getJson("/api/v1/talent-learning/courses/{$draft['id']}")->assertOk();
    $this->postJson('/api/v1/talent-learning/courses', ['code' => 'X', 'title' => 'x', 'delivery' => 'online', 'duration_hours' => 1])->assertForbidden();
    $this->postJson("/api/v1/talent-learning/courses/{$draft['id']}/publish")->assertForbidden();
    $this->getJson('/api/v1/talent-learning/enrolments')->assertForbidden();
    $this->getJson('/api/v1/talent-learning/certificates')->assertForbidden();
    $this->getJson('/api/v1/talent-learning/compliance')->assertForbidden();
    $this->getJson('/api/v1/talent-learning/dashboard')->assertForbidden();

    tlAct(tlMember($this->tenant, 'member', ['talent_learning.manage']), $this->tenant);
    $this->getJson('/api/v1/talent-learning/enrolments')->assertForbidden();
    tlAct(tlMember($this->tenant, 'hr'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/enrolments')->assertOk();
});

test('courses are unique by code, validated and move through publish and archive', function (): void {
    $course = tlCourse(['code' => 'FS-01'], false);
    $this->postJson('/api/v1/talent-learning/courses', ['code' => 'FS-01', 'title' => 'Again', 'delivery' => 'online', 'duration_hours' => 1])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/talent-learning/courses', ['code' => 'FS-02', 'title' => 'Bad', 'delivery' => 'telepathy', 'duration_hours' => 1, 'pass_score' => 120])->assertUnprocessable()->assertJsonValidationErrors(['delivery', 'pass_score']);

    $this->putJson("/api/v1/talent-learning/courses/{$course['id']}", ['duration_hours' => 0])->assertOk();
    $this->postJson("/api/v1/talent-learning/courses/{$course['id']}/publish")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->putJson("/api/v1/talent-learning/courses/{$course['id']}", ['duration_hours' => 3, 'is_mandatory' => true, 'validity_months' => 12])->assertOk()->assertJsonPath('data.is_mandatory', true);
    $this->postJson("/api/v1/talent-learning/courses/{$course['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->postJson("/api/v1/talent-learning/courses/{$course['id']}/publish")->assertUnprocessable();
    $this->postJson("/api/v1/talent-learning/courses/{$course['id']}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    $this->getJson('/api/v1/talent-learning/courses?status=archived&is_mandatory=1&search=fs-0')->assertOk()->assertJsonCount(1, 'data');

    tlEnrol($course['id'], tlEmployee($this->tenant)->id)->assertUnprocessable()->assertJsonValidationErrors('course_id');
    $this->deleteJson("/api/v1/talent-learning/courses/{$course['id']}")->assertNoContent();
});

test('enrolment progress only grows and completion issues a numbered certificate that expires', function (): void {
    $course = tlCourse(['validity_months' => 12, 'pass_score' => 70]);
    $employee = tlEmployee($this->tenant);

    $enrolment = tlEnrol($course['id'], $employee->id, ['due_on' => '2027-07-01'])->assertCreated()
        ->assertJsonPath('data.status', 'enrolled')->assertJsonPath('data.employee.full_name', $employee->full_name)->assertJsonPath('data.is_overdue', false)->json('data');
    tlEnrol($course['id'], $employee->id)->assertUnprocessable()->assertJsonValidationErrors('course_id');

    $path = "/api/v1/talent-learning/enrolments/{$enrolment['id']}";
    $this->postJson("$path/progress", ['progress_percent' => 40])->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.progress_percent', 40);
    $this->postJson("$path/progress", ['progress_percent' => 20])->assertUnprocessable()->assertJsonValidationErrors('progress_percent');
    $this->postJson("$path/progress", ['progress_percent' => 140])->assertUnprocessable();

    $this->postJson("$path/complete")->assertUnprocessable()->assertJsonValidationErrors('score');
    $this->postJson("$path/complete", ['score' => 50])->assertUnprocessable()->assertJsonValidationErrors('score');
    $done = $this->postJson("$path/complete", ['score' => 85])->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress_percent', 100)->assertJsonPath('data.certificate.certificate_number', 'CERT-2027-00001')
        ->assertJsonPath('data.certificate.expires_on', '2028-06-15')->json('data');
    $this->postJson("$path/complete", ['score' => 85])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("$path/cancel")->assertUnprocessable();
    $this->assertSame('2027-06-15', LmsCertificate::query()->firstOrFail()->issued_on->toDateString());
    $this->assertNotNull($done['certificate']['id']);

    tlEnrol($course['id'], $employee->id)->assertUnprocessable()->assertJsonValidationErrors('course_id');
    $this->getJson('/api/v1/talent-learning/certificates?validity=valid')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_valid', true);

    $this->travelTo('2028-07-01 09:00:00');
    $this->getJson('/api/v1/talent-learning/certificates?validity=expired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_valid', false);
    tlEnrol($course['id'], $employee->id)->assertCreated();

    $second = tlEmployee($this->tenant);
    $other = tlEnrol($course['id'], $second->id)->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/enrolments/{$other['id']}/complete", ['score' => 99])->assertOk()->assertJsonPath('data.certificate.certificate_number', 'CERT-2028-00002');
});

test('a cancelled enrolment can be redone and an enrolment needs an active employee of the company', function (): void {
    $course = tlCourse();
    $employee = tlEmployee($this->tenant);
    $enrolment = tlEnrol($course['id'], $employee->id)->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/enrolments/{$enrolment['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/talent-learning/enrolments/{$enrolment['id']}/progress", ['progress_percent' => 10])->assertUnprocessable();
    tlEnrol($course['id'], $employee->id)->assertCreated();

    tlEnrol($course['id'], tlEmployee($this->tenant, ['employment_status' => 'terminated'])->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    tlEnrol($course['id'], tlEmployee(tlTenant('Other'))->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    tlEnrol($course['id'], $employee->id, ['due_on' => '15/07/2027'])->assertUnprocessable()->assertJsonValidationErrors('due_on');
});

test('enrolments, certificates and courses of another company are invisible', function (): void {
    $course = tlCourse();
    $employee = tlEmployee($this->tenant);
    $enrolment = tlEnrol($course['id'], $employee->id)->assertCreated()->json('data');

    $other = tlTenant('Other');
    tlAct(tlMember($other, 'hr'), $other);
    $this->getJson('/api/v1/talent-learning/enrolments')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/talent-learning/enrolments/{$enrolment['id']}")->assertNotFound();
    $this->postJson("/api/v1/talent-learning/enrolments/{$enrolment['id']}/progress", ['progress_percent' => 5])->assertNotFound();
    $this->getJson("/api/v1/talent-learning/courses/{$course['id']}")->assertNotFound();
    tlEnrol($course['id'], tlEmployee($other)->id)->assertUnprocessable()->assertJsonValidationErrors('course_id');
});

test('a learning path is ordered, published only with published courses and enrols employees in each course', function (): void {
    $first = tlCourse(['title' => 'Basics', 'validity_months' => 6]);
    $second = tlCourse(['title' => 'Advanced']);
    $draft = tlCourse(['title' => 'Unfinished'], false);
    $employee = tlEmployee($this->tenant);

    $this->postJson('/api/v1/talent-learning/learning-paths', ['name' => 'Dup', 'course_ids' => [$first['id'], $first['id']]])->assertUnprocessable()->assertJsonValidationErrors('course_ids');
    $this->postJson('/api/v1/talent-learning/learning-paths', ['name' => 'Ghost', 'course_ids' => [(string) Str::uuid()]])->assertUnprocessable()->assertJsonValidationErrors('course_ids');

    $path = $this->postJson('/api/v1/talent-learning/learning-paths', ['name' => 'Onboarding', 'course_ids' => [$second['id'], $first['id']]])->assertCreated()
        ->assertJsonPath('data.courses.0.course.title', 'Advanced')->assertJsonPath('data.courses.1.position', 2)->json('data');
    $this->postJson("/api/v1/talent-learning/learning-paths/{$path['id']}/enrol", ['employee_ids' => [$employee->id]])->assertUnprocessable();

    $withDraft = $this->postJson('/api/v1/talent-learning/learning-paths', ['name' => 'Incomplete', 'course_ids' => [$first['id'], $draft['id']]])->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/learning-paths/{$withDraft['id']}/publish")->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->postJson("/api/v1/talent-learning/learning-paths/{$path['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    tlEnrol($first['id'], $employee->id)->assertCreated();
    $this->postJson("/api/v1/talent-learning/learning-paths/{$path['id']}/enrol", ['employee_ids' => [$employee->id], 'due_on' => '2027-09-01'])->assertCreated()->assertJsonCount(1, 'data')->assertJsonPath('data.0.course_id', $second['id'])->assertJsonPath('data.0.learning_path_id', $path['id']);

    $this->putJson("/api/v1/talent-learning/learning-paths/{$path['id']}", ['course_ids' => [$first['id']]])->assertOk()->assertJsonCount(1, 'data.courses');
    $this->deleteJson("/api/v1/talent-learning/learning-paths/{$path['id']}")->assertUnprocessable();
    $this->deleteJson("/api/v1/talent-learning/courses/{$first['id']}")->assertUnprocessable();
    $this->deleteJson("/api/v1/talent-learning/learning-paths/{$withDraft['id']}")->assertNoContent();

    tlAct(tlMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/learning-paths')->assertOk()->assertJsonCount(1, 'data');
});

test('mandatory training compliance counts valid, pending, overdue, expired and missing employees', function (): void {
    $course = tlCourse(['title' => 'GDPR', 'is_mandatory' => true, 'validity_months' => 12]);
    tlCourse(['title' => 'Optional']);
    $compliant = tlEmployee($this->tenant);
    $pending = tlEmployee($this->tenant);
    $overdue = tlEmployee($this->tenant);
    $expired = tlEmployee($this->tenant);
    $missing = tlEmployee($this->tenant);

    $done = tlEnrol($course['id'], $expired->id)->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/enrolments/{$done['id']}/complete")->assertOk();
    $this->travelTo('2028-07-01 09:00:00');
    $current = tlEnrol($course['id'], $compliant->id)->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/enrolments/{$current['id']}/complete")->assertOk();
    tlEnrol($course['id'], $pending->id, ['due_on' => '2028-08-01'])->assertCreated();
    tlEnrol($course['id'], $overdue->id, ['due_on' => '2028-06-01'])->assertCreated();

    $this->getJson('/api/v1/talent-learning/enrolments?overdue=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $overdue->id)->assertJsonPath('data.0.is_overdue', true);

    $report = $this->getJson('/api/v1/talent-learning/compliance')->assertOk()
        ->assertJsonCount(1, 'data.courses')
        ->assertJsonPath('data.courses.0.employees_total', 5)
        ->assertJsonPath('data.courses.0.compliant', 1)
        ->assertJsonPath('data.courses.0.in_progress', 1)
        ->assertJsonPath('data.courses.0.overdue', 1)
        ->assertJsonPath('data.courses.0.expired', 1)
        ->assertJsonPath('data.courses.0.missing', 1)
        ->assertJsonPath('data.courses.0.compliance_percent', 20)
        ->assertJsonPath('data.compliance_percent', 20);
    $this->assertArrayNotHasKey('non_compliant', $report->json('data.courses.0'));

    $detail = $this->getJson("/api/v1/talent-learning/compliance?course_id={$course['id']}")->assertOk()->json('data.courses.0.non_compliant');
    $states = collect($detail)->mapWithKeys(fn (array $row): array => [$row['employee']['id'] => $row['state']]);
    $this->assertSame(['pending' => 'in_progress', 'overdue' => 'overdue', 'expired' => 'expired', 'missing' => 'missing'], [
        'pending' => $states[$pending->id], 'overdue' => $states[$overdue->id], 'expired' => $states[$expired->id], 'missing' => $states[$missing->id],
    ]);
    $this->assertCount(4, $detail);

    $this->getJson('/api/v1/talent-learning/dashboard')->assertOk()
        ->assertJsonPath('data.learning.courses_published', 2)
        ->assertJsonPath('data.learning.overdue_enrolments', 1)
        ->assertJsonPath('data.learning.certificates_issued', 2)
        ->assertJsonPath('data.learning.certificates_expired', 1)
        ->assertJsonPath('data.learning.mandatory_compliance_percent', 20)
        ->assertJsonPath('data.engagement', null);
});

test('learners see their own enrolments and certificates and record their own progress only', function (): void {
    $course = tlCourse(['validity_months' => 12]);
    $learner = tlLearner($this->tenant);
    $colleague = tlLearner($this->tenant);
    $mine = tlEnrol($course['id'], $learner['employee']->id)->assertCreated()->json('data');
    $theirs = tlEnrol($course['id'], $colleague['employee']->id)->assertCreated()->json('data');

    tlAct($learner['user'], $this->tenant);
    $this->getJson('/api/v1/talent-learning/my/enrolments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id'])->assertJsonPath('data.0.course.title', 'Fire safety');
    $this->postJson("/api/v1/talent-learning/my/enrolments/{$mine['id']}/progress", ['progress_percent' => 100])->assertOk()->assertJsonPath('data.progress_percent', 99)->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/talent-learning/my/enrolments/{$theirs['id']}/progress", ['progress_percent' => 10])->assertNotFound();
    $this->postJson("/api/v1/talent-learning/enrolments/{$mine['id']}/progress", ['progress_percent' => 100])->assertForbidden();
    $this->postJson("/api/v1/talent-learning/enrolments/{$mine['id']}/complete")->assertForbidden();

    tlAct($this->hr, $this->tenant);
    $this->postJson("/api/v1/talent-learning/enrolments/{$mine['id']}/complete")->assertOk();

    tlAct($learner['user'], $this->tenant);
    $this->getJson('/api/v1/talent-learning/my/certificates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $learner['employee']->id);

    tlAct(tlMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/my/enrolments')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/talent-learning/my/enrolments/{$mine['id']}/progress", ['progress_percent' => 5])->assertNotFound();
});
