<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\InterviewScorecard;

use function Tests\Feature\HumanResources\Recruiting\publishedOpening;
use function Tests\Feature\HumanResources\Recruiting\recruiterOfNewTenant;
use function Tests\Feature\HumanResources\Recruiting\useTenant;

require_once __DIR__.'/RecruitingTestSupport.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('private');
    Mail::fake();
});

/**
 * @return array{0: CandidateApplication, 1: string}
 */
function expiredCandidateWithCv(Tenant $tenant, string $jobOpeningId): array
{
    useTenant($tenant);
    $path = "tenants/{$tenant->id}/recruiting/cvs/".fake()->uuid().'.pdf';
    Storage::disk('private')->put($path, '%PDF-1.4 cv');

    $application = CandidateApplication::factory()->consentExpired()->create([
        'tenant_id' => $tenant->id,
        'job_opening_id' => $jobOpeningId,
        'stage' => 'rejected',
        'rejection_reason_code' => 'not_qualified',
        'rejection_note' => 'Missing certification',
        'notes' => 'Called on Monday',
        'cover_letter' => 'Dear HR',
        'skills' => ['PHP'],
        'extracted_profile' => ['education' => [], 'experience' => [], 'languages' => [], 'skills' => ['PHP']],
        'cv_storage_key' => $path,
        'cv_original_name' => 'cv.pdf',
        'cv_mime_type' => 'application/pdf',
        'cv_size_bytes' => 11,
    ]);

    InterviewScorecard::factory()->create([
        'tenant_id' => $tenant->id,
        'application_id' => $application->id,
        'notes' => 'Personal remark',
    ]);

    return [$application, $path];
}

test('the purge command anonymises expired candidates, deletes their CV and is idempotent', function () {
    $tenant = Tenant::factory()->create();
    $opening = publishedOpening($tenant);
    [$expired, $cvPath] = expiredCandidateWithCv($tenant, $opening->id);

    $valid = CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $hired = CandidateApplication::factory()->consentExpired()->create([
        'tenant_id' => $tenant->id,
        'job_opening_id' => $opening->id,
        'stage' => 'hired',
        'hired_employee_id' => $employee->id,
    ]);

    $this->artisan('hr:recruiting:purge-expired-candidates', ['--dry-run' => true, '--tenant' => $tenant->id])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    useTenant($tenant);
    expect($expired->fresh()->anonymized_at)->toBeNull();
    Storage::disk('private')->assertExists($cvPath);

    $this->artisan('hr:recruiting:purge-expired-candidates', ['--tenant' => $tenant->id])->assertSuccessful();

    useTenant($tenant);
    $anonymized = $expired->fresh();
    $scorecard = InterviewScorecard::query()->where('application_id', $expired->id)->firstOrFail();

    expect($anonymized->anonymized_at)->not->toBeNull()
        ->and($anonymized->first_name)->toBe('Anonymized')
        ->and($anonymized->last_name)->toBe('Candidate')
        ->and($anonymized->email)->toBeNull()
        ->and($anonymized->phone)->toBeNull()
        ->and($anonymized->notes)->toBeNull()
        ->and($anonymized->cover_letter)->toBeNull()
        ->and($anonymized->cv_storage_key)->toBeNull()
        ->and($anonymized->extracted_profile)->toBeNull()
        ->and($anonymized->skills)->toBe([])
        ->and($anonymized->stage->value)->toBe('rejected')
        ->and($anonymized->rejection_reason_code)->toBe('not_qualified')
        ->and($anonymized->rejection_note)->toBeNull()
        ->and($scorecard->notes)->toBeNull()
        ->and($scorecard->criteria_scores[0]['comment'])->toBeNull()
        ->and((float) $scorecard->overall_score)->toBe(3.5)
        ->and($valid->fresh()->anonymized_at)->toBeNull()
        ->and($hired->fresh()->anonymized_at)->toBeNull()
        ->and($hired->fresh()->email)->not->toBeNull();

    Storage::disk('private')->assertMissing($cvPath);

    $anonymizedAt = $anonymized->anonymized_at->toIso8601String();
    $this->artisan('hr:recruiting:purge-expired-candidates', ['--tenant' => $tenant->id])
        ->expectsOutputToContain('0 expired candidate(s) found')
        ->assertSuccessful();

    useTenant($tenant);
    expect($expired->fresh()->anonymized_at->toIso8601String())->toBe($anonymizedAt);
});

test('the purge of one tenant leaves other tenants untouched', function () {
    $tenantA = Tenant::factory()->create();
    [$expiredA] = expiredCandidateWithCv($tenantA, publishedOpening($tenantA)->id);

    $tenantB = Tenant::factory()->create();
    [$expiredB, $cvPathB] = expiredCandidateWithCv($tenantB, publishedOpening($tenantB)->id);

    $this->artisan('hr:recruiting:purge-expired-candidates', ['--tenant' => $tenantA->id])->assertSuccessful();

    useTenant($tenantA);
    expect($expiredA->fresh()->anonymized_at)->not->toBeNull();

    useTenant($tenantB);
    expect($expiredB->fresh()->anonymized_at)->toBeNull()
        ->and($expiredB->fresh()->email)->not->toBeNull();
    Storage::disk('private')->assertExists($cvPathB);
});

test('erasure on request anonymises immediately and blocks further mutations', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    [$application, $cvPath] = expiredCandidateWithCv($tenant, $opening->id);
    $application->forceFill(['stage' => 'applied', 'privacy_consent_expires_at' => now()->addMonths(3)])->save();
    $headers = ['X-Tenant-Id' => $tenant->id];

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/anonymize")
        ->assertOk()
        ->assertJsonPath('data.is_anonymized', true)
        ->assertJsonPath('data.full_name', 'Anonymized Candidate')
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.cv', null);

    Storage::disk('private')->assertMissing($cvPath);

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/move", ['stage' => 'hr_screening'])
        ->assertStatus(409)->assertJsonPath('error_code', 'candidate_anonymized');
    $this->withHeaders($headers)->patchJson("/api/v1/hr/recruiting/applications/{$application->id}", ['notes' => 'x'])
        ->assertStatus(409)->assertJsonPath('error_code', 'candidate_anonymized');
    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$application->id}/extend-consent", ['months' => 6])
        ->assertStatus(409)->assertJsonPath('error_code', 'candidate_anonymized');
    $this->withHeaders($headers)->get("/api/v1/hr/recruiting/applications/{$application->id}/cv", ['Accept' => 'application/json'])
        ->assertNotFound();
});

test('extending consent moves the expiry date and the expiring filter lists candidates to contact', function () {
    [$tenant] = recruiterOfNewTenant();
    $opening = publishedOpening($tenant);
    $soon = CandidateApplication::factory()->create([
        'tenant_id' => $tenant->id,
        'job_opening_id' => $opening->id,
        'privacy_consent_expires_at' => now()->addDays(10),
    ]);
    CandidateApplication::factory()->create(['tenant_id' => $tenant->id, 'job_opening_id' => $opening->id]);
    $headers = ['X-Tenant-Id' => $tenant->id];

    $this->withHeaders($headers)->getJson('/api/v1/hr/recruiting/applications?consent_expiring_within_days=30')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $soon->id);

    $response = $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$soon->id}/extend-consent", ['months' => 12]);
    $response->assertOk();

    expect(substr((string) $response->json('data.consent.expires_at'), 0, 10))->toBe(now()->addMonthsNoOverflow(12)->toDateString())
        ->and($response->json('data.consent.extended_at'))->not->toBeNull();

    $this->withHeaders($headers)->postJson("/api/v1/hr/recruiting/applications/{$soon->id}/extend-consent", ['months' => 7])
        ->assertStatus(422);
});
