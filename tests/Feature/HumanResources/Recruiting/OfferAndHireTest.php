<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Modules\HumanResources\Contracts\OnboardingStarterInterface;
use Modules\HumanResources\DTOs\OnboardingStartResultDTO;
use Modules\HumanResources\Events\CandidateHiredEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeLifecycleEvent;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\JobOffer;
use Modules\HumanResources\Models\JobOpening;

use function Tests\Feature\HumanResources\Recruiting\positionFor;
use function Tests\Feature\HumanResources\Recruiting\publishedOpening;
use function Tests\Feature\HumanResources\Recruiting\recruiterOfNewTenant;
use function Tests\Feature\HumanResources\Recruiting\useTenant;

require_once __DIR__.'/RecruitingTestSupport.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
});

test('offer then hire converts the candidate into an employee exactly once and fills the opening', function () {
    Event::fake([EmployeeHiredEvent::class, CandidateHiredEvent::class]);

    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant, ['openings_count' => 1]);
    $application = CandidateApplication::factory()->create([
        'tenant_id' => $tenant->id,
        'job_opening_id' => $opening->id,
        'stage' => 'manager_interview',
        'phone' => '+41 79 000 00 00',
    ]);
    $headers = ['X-Tenant-Id' => $tenant->id];

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/hire")
        ->assertStatus(409)->assertJsonPath('error_code', 'offer_not_signed');

    $offerResponse = $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/offer", [
        'proposed_salary' => 84000,
        'currency' => 'CHF',
        'start_date' => now()->addMonth()->toDateString(),
        'weekly_hours' => 40,
        'probation_months' => 3,
    ]);
    $offerResponse->assertCreated()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.signature_status', 'pending')
        ->assertJsonPath('data.signature_provider', 'manual');
    expect($offerResponse->json('data.rendered_body'))->toContain('84&#039;000.00');
    $offerId = $offerResponse->json('data.id');

    $this->withHeaders($headers)->getJson("/api/v1/hr/recruiting/applications/{$application->id}")
        ->assertJsonPath('data.stage', 'offer_extended')
        ->assertJsonPath('data.offer.id', $offerId);

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/offer", [
        'proposed_salary' => 90000,
        'start_date' => now()->addMonth()->toDateString(),
    ])->assertStatus(409)->assertJsonPath('error_code', 'offer_already_exists');

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/hire")
        ->assertStatus(409)->assertJsonPath('error_code', 'offer_not_signed');

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/offers/{$offerId}/mark-signed")
        ->assertOk()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.signature_status', 'signed');

    useTenant($tenant);
    $employeesBefore = Employee::query()->count();

    $hire = $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/hire", [
        'work_email' => 'new.hire@acme.ch',
    ]);

    $hire->assertCreated()
        ->assertJsonPath('data.application.stage', 'hired')
        ->assertJsonPath('data.application.consent.expires_at', null)
        ->assertJsonPath('data.job_opening.hires_count', 1)
        ->assertJsonPath('data.job_opening.status', 'closed_filled')
        ->assertJsonPath('data.onboarding.status', 'not_available')
        ->assertJsonPath('data.onboarding.checklist_id', null);

    useTenant($tenant);
    $employeeId = $hire->json('data.employee.id');
    /** @var Employee $employee */
    $employee = Employee::query()->findOrFail($employeeId);

    expect(Employee::query()->count())->toBe($employeesBefore + 1)
        ->and($hire->json('data.application.hired_employee_id'))->toBe($employeeId)
        ->and($employee->work_email)->toBe('new.hire@acme.ch')
        ->and($employee->personal_email)->toBe($application->email)
        ->and($employee->phone)->toBe('+41 79 000 00 00')
        ->and($employee->position_id)->toBe($opening->position_id)
        ->and((float) $employee->base_annual_salary)->toBe(84000.0)
        ->and(EmploymentContract::query()->where('employee_id', $employeeId)->count())->toBe(1)
        ->and(EmployeeLifecycleEvent::query()->where('employee_id', $employeeId)->where('event_type', 'hiring')->count())->toBe(1);

    Event::assertDispatchedTimes(EmployeeHiredEvent::class, 1);
    Event::assertDispatchedTimes(CandidateHiredEvent::class, 1);

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/hire")
        ->assertStatus(409)->assertJsonPath('error_code', 'already_hired');

    useTenant($tenant);
    expect(Employee::query()->count())->toBe($employeesBefore + 1);
    Event::assertDispatchedTimes(EmployeeHiredEvent::class, 1);
});

test('a failure inside the hire transaction rolls everything back', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant, ['openings_count' => 1]);
    $application = CandidateApplication::factory()->create([
        'tenant_id' => $tenant->id,
        'job_opening_id' => $opening->id,
        'stage' => 'offer_extended',
    ]);
    JobOffer::factory()->signed()->create(['tenant_id' => $tenant->id, 'application_id' => $application->id]);

    app()->instance(OnboardingStarterInterface::class, new class implements OnboardingStarterInterface
    {
        public function start(Employee $employee, CandidateApplication $application): OnboardingStartResultDTO
        {
            throw new RuntimeException('Onboarding engine unavailable');
        }
    });

    useTenant($tenant);
    $employeesBefore = Employee::query()->count();

    $this->withHeader('X-Tenant-Id', $tenant->id)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/hire")
        ->assertStatus(500);

    useTenant($tenant);
    $application->refresh();
    $opening->refresh();

    expect(Employee::query()->count())->toBe($employeesBefore)
        ->and(Employee::query()->where('work_email', $application->email)->exists())->toBeFalse()
        ->and($application->stage->value)->toBe('offer_extended')
        ->and($application->hired_employee_id)->toBeNull()
        ->and($application->privacy_consent_expires_at)->not->toBeNull()
        ->and($opening->hires_count)->toBe(0)
        ->and($opening->status->value)->toBe('published');
});

test('openings cannot be created or published beyond the position headcount', function () {
    [$tenant] = recruiterOfNewTenant();
    $position = positionFor($tenant, headcount: 1);
    Employee::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $position->department_id,
        'position_id' => $position->id,
    ]);
    $headers = ['X-Tenant-Id' => $tenant->id];

    $payload = [
        'position_id' => $position->id,
        'location_site' => 'Mendrisio',
        'employment_type' => 'full_time',
        'published_languages' => ['it-CH'],
        'translations' => ['it-CH' => ['title' => 'Contabile', 'description' => 'Contabilità generale']],
    ];

    $this->withHeaders($headers)->postJson('/api/v1/hr/recruiting/job-openings', $payload)
        ->assertStatus(409)->assertJsonPath('error_code', 'no_vacancy');

    $freePosition = positionFor($tenant, headcount: 1);
    $created = $this->withHeaders($headers)->postJson('/api/v1/hr/recruiting/job-openings', [...$payload, 'position_id' => $freePosition->id]);
    $created->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.position_title', $freePosition->title);

    useTenant($tenant);
    Employee::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $freePosition->department_id,
        'position_id' => $freePosition->id,
    ]);

    $this->withHeaders($headers)->postJson('/api/v1/hr/recruiting/job-openings/'.$created->json('data.id').'/publish')
        ->assertStatus(409)->assertJsonPath('error_code', 'opening_not_publishable');
});

test('publishing requires every published language to be translated', function () {
    [$tenant] = recruiterOfNewTenant();
    $position = positionFor($tenant, headcount: 3);
    $headers = ['X-Tenant-Id' => $tenant->id];

    $this->withHeaders($headers)->postJson('/api/v1/hr/recruiting/job-openings', [
        'position_id' => $position->id,
        'location_site' => 'Bellinzona',
        'employment_type' => 'part_time',
        'published_languages' => ['it-CH', 'de-CH'],
        'translations' => ['it-CH' => ['title' => 'Impiegato', 'description' => 'Ufficio']],
    ])->assertStatus(422)->assertJsonValidationErrors(['translations.de-CH.title']);

    $created = $this->withHeaders($headers)->postJson('/api/v1/hr/recruiting/job-openings', [
        'position_id' => $position->id,
        'location_site' => 'Bellinzona',
        'employment_type' => 'part_time',
        'openings_count' => 2,
        'salary_range_public' => ['min' => 50000, 'max' => 60000, 'currency' => 'CHF'],
        'published_languages' => ['it-CH'],
        'translations' => ['it-CH' => ['title' => 'Impiegato', 'description' => 'Ufficio']],
    ])->assertCreated()
        ->assertJsonPath('data.salary_range_public.period', 'year')
        ->assertJsonPath('data.applications_count', 0);

    $id = $created->json('data.id');

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/job-openings/{$id}/publish", ['internal_only' => true])
        ->assertOk()->assertJsonPath('data.status', 'internal_only');

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/job-openings/{$id}/close", ['outcome' => 'cancelled'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->withHeaders($headers)->putJson("/api/v1/hr/recruiting/job-openings/{$id}", ['location_site' => 'Lugano'])
        ->assertForbidden();

    useTenant($tenant);
    expect(JobOpening::query()->findOrFail($id)->location_site)->toBe('Bellinzona');
});
