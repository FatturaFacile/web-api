<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;
use Modules\Hse\Infrastructure\Models\ToolBoxTalkAttendance;
use Modules\HumanResources\Models\EmployeeDocument;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
});

it('registers a completed training and computes the next refresher from the statutory cycle', function (string $country, CertificateType $type, bool $specificRisk, ?string $expected): void {
    $tenant = hseTenant($country);
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);

    $this->postJson('/api/v1/hse/certificates', [
        'employee_id' => $employee->id, 'certificate_type' => $type->value, 'issued_at' => '2027-03-10', 'hours_completed' => 8, 'specific_risk_class' => $specificRisk,
    ])->assertCreated()->assertJsonPath('data.expires_at', $expected);
})->with([
    'IT general module never expires' => ['IT', CertificateType::GeneralSafety, false, null],
    'IT specific training five years' => ['IT', CertificateType::HighRiskSpecific, false, '2032-03-10'],
    'IT first aid in a specific risk class two years' => ['IT', CertificateType::FirstAid, true, '2029-03-10'],
    'IT fire safety three years' => ['IT', CertificateType::FireSafety, false, '2030-03-10'],
    'CH forklift three years' => ['CH', CertificateType::Forklift, false, '2030-03-10'],
]);

it('keeps an explicit expiry, refuses one before the issue date, and files the signed certificate in the dossier', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hr');
    $employee = hseEmployee($tenant);
    $payload = ['employee_id' => $employee->id, 'certificate_type' => 'forklift_patentino_muletto', 'issued_at' => '2027-03-10', 'expires_at' => '2029-01-01'];

    $this->postJson('/api/v1/hse/certificates', [...$payload, 'expires_at' => '2027-01-01'])->assertUnprocessable();

    $response = $this->postJson('/api/v1/hse/certificates', [...$payload, 'certificate_pdf_base64' => base64_encode('%PDF-1.4 fake')])->assertCreated()->assertJsonPath('data.expires_at', '2029-01-01');

    expect(EmployeeSafetyCertificate::query()->findOrFail($response->json('data.id'))->document_id)->not->toBeNull()
        ->and(EmployeeDocument::query()->where('document_type', 'safety_certificate')->count())->toBe(1);
});

it('clears a pending expiration block as soon as the renewed certificate is registered', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);
    $target = (string) Str::uuid();
    hseMatrix($tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_training_types_json' => [['type' => CertificateType::Forklift->value]]]);
    hseCertificate($tenant, $employee, CertificateType::Forklift, '2020-01-01', '2023-01-01');
    $query = fn (): array => $this->getJson('/api/v1/hse/clearance?'.http_build_query(['employee_id' => $employee->id, 'target_context_type' => 'production_work_center', 'target_id' => $target, 'scheduled_date_time' => now()->toIso8601String()]))->json('data');

    expect($query()['is_cleared'])->toBeFalse();

    $this->postJson('/api/v1/hse/certificates', ['employee_id' => $employee->id, 'certificate_type' => 'forklift_patentino_muletto', 'issued_at' => now()->toDateString()])->assertCreated();

    expect($query()['is_cleared'])->toBeTrue();
});

it('refuses a requirement rule without any target scope', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');

    $this->postJson('/api/v1/hse/requirement-matrices', ['name' => 'Orphan', 'jurisdiction' => 'IT'])->assertUnprocessable();
    expect(fn () => SafetyRoleRequirementMatrix::create(['tenant_id' => $tenant->id, 'name' => 'Orphan', 'jurisdiction' => 'IT']))->toThrow(HseException::class);
});

it('saves a rule, bumps its version and refuses a stale edit', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');
    $body = ['name' => 'Press operator', 'jurisdiction' => 'CH', 'work_center_id' => (string) Str::uuid(), 'suva_hazard_class' => '41A', 'required_training_types' => [['type' => 'forklift_patentino_muletto', 'validity_months' => 36]]];

    $created = $this->postJson('/api/v1/hse/requirement-matrices', $body)->assertCreated()->assertJsonPath('data.version', 1);
    $id = $created->json('data.id');

    $this->putJson("/api/v1/hse/requirement-matrices/{$id}", [...$body, 'name' => 'Press operator v2', 'expected_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    $this->putJson("/api/v1/hse/requirement-matrices/{$id}", [...$body, 'expected_version' => 1])->assertUnprocessable();
    $this->postJson('/api/v1/hse/requirement-matrices', [...$body, 'required_training_types' => [['type' => 'nonsense']]])->assertUnprocessable();
});

it('builds the traffic-light matrix with KPI cards and filters', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');
    $target = (string) Str::uuid();
    hseMatrix($tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_training_types_json' => [['type' => CertificateType::Forklift->value]], 'required_medical_exam_codes_json' => ['GEN']]);

    $green = hseEmployee($tenant, ['first_name' => 'Anna', 'last_name' => 'Verdi']);
    hseCertificate($tenant, $green, CertificateType::Forklift, '2026-01-01', now()->addYears(3)->toDateString());
    hseMedicalVisit($tenant, $green, FitnessOutcome::Fit, now()->subMonth()->toDateString(), now()->addYear()->toDateString());

    $yellow = hseEmployee($tenant, ['first_name' => 'Bruno', 'last_name' => 'Neri']);
    hseCertificate($tenant, $yellow, CertificateType::Forklift, '2026-01-01', now()->addDays(20)->toDateString());
    hseMedicalVisit($tenant, $yellow, FitnessOutcome::Fit, now()->subMonth()->toDateString(), now()->addYear()->toDateString());

    $red = hseEmployee($tenant, ['first_name' => 'Carla', 'last_name' => 'Rossi']);
    hseMedicalVisit($tenant, $red, FitnessOutcome::TemporarilyUnfit, now()->subMonth()->toDateString());

    $response = $this->getJson('/api/v1/hse/compliance-matrix?work_center_id='.$target)->assertOk();
    $lights = collect($response->json('data'))->pluck('overall', 'employee_name');

    expect($lights['Anna Verdi'])->toBe('GREEN')->and($lights['Bruno Neri'])->toBe('YELLOW')->and($lights['Carla Rossi'])->toBe('RED')
        ->and($response->json('meta.kpis.hard_blocked_workers'))->toBe(1)
        ->and($response->json('meta.kpis.overall_compliance_percent'))->toBe(33.3)
        ->and($response->json('meta.kpis.expiring_in_30_days'))->toBe(1);

    $this->getJson('/api/v1/hse/compliance-matrix?work_center_id='.$target.'&status=RED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_name', 'Carla Rossi');
    $this->getJson('/api/v1/hse/compliance-matrix?work_center_id='.$target.'&per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
});

it('lists the expiring certificates, medical visits and PPE for the dashboard widgets', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);
    hseCertificate($tenant, $employee, CertificateType::FirstAid, '2024-01-01', now()->addDays(10)->toDateString());
    hseMedicalVisit($tenant, $employee, FitnessOutcome::Fit, now()->subYear()->toDateString(), now()->addDays(40)->toDateString());
    hseCertificate($tenant, $employee, CertificateType::FireSafety, '2024-01-01', now()->addYears(2)->toDateString());

    $this->getJson('/api/v1/hse/expirations?within_days=60')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.subject_type', 'certificate')->assertJsonPath('data.1.subject_type', 'medical_visit');
});

it('records a tool-box talk with hashed signatures and ignores a second signature of the same worker', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');
    $one = hseEmployee($tenant);
    $two = hseEmployee($tenant);
    $body = [
        'topic' => 'Ladder safety', 'site_or_work_center' => 'LUG-PLANT-02', 'checklist_points' => ['Check the feet', 'Three points of contact'], 'conducted_at' => '2027-06-15T07:30:00+02:00',
        'attendances' => [
            ['employee_id' => (string) $one->id, 'signature_payload' => '[[1,2]]', 'signature_image' => 'abc', 'signed_at' => '2027-06-15T07:40:00+02:00'],
            ['employee_id' => (string) $two->id, 'signature_payload' => '[[3,4]]', 'signed_at' => '2027-06-15T07:41:00+02:00'],
        ],
    ];

    $talk = $this->postJson('/api/v1/hse/toolbox-talks', $body)->assertCreated()->assertJsonPath('data.attendees', 2);
    $this->postJson('/api/v1/hse/toolbox-talks', [...$body, 'talk_id' => $talk->json('data.id')])->assertCreated()->assertJsonPath('data.attendees', 2);

    expect(ToolBoxTalkAttendance::query()->count())->toBe(2)
        ->and(ToolBoxTalkAttendance::query()->where('employee_id', $one->id)->value('signature_hash'))->toHaveLength(64);
});

it('refuses a tool-box talk signed by someone who is not an employee of the company', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');

    $this->postJson('/api/v1/hse/toolbox-talks', [
        'topic' => 'x', 'conducted_at' => '2027-06-15T07:30:00+02:00',
        'attendances' => [['employee_id' => (string) Str::uuid(), 'signature_payload' => '[[1,2]]', 'signed_at' => '2027-06-15T07:40:00+02:00']],
    ])->assertUnprocessable();
});

it('scores the 10-point MSSL/ASA audit for Swiss tenants only', function (): void {
    $tenant = hseTenant('CH');
    hseActAs($tenant, 'hse');
    $answers = array_fill(1, 10, 'yes');
    $answers[3] = 'partial';
    $answers[7] = 'no';

    $this->postJson('/api/v1/hse/audits/mssl', ['audit_date' => '2027-06-01', 'answers' => $answers, 'corrective_actions' => [['point' => 7, 'description' => 'Introduce the emergency plan']]])
        ->assertCreated()->assertJsonPath('data.status', 'open');
    $this->postJson('/api/v1/hse/audits/mssl', ['audit_date' => '2027-06-01', 'answers' => array_slice($answers, 0, 9, true)])->assertUnprocessable();

    $audits = $this->getJson('/api/v1/hse/audits')->assertOk()->assertJsonCount(1, 'data.mssl_audits');
    expect((float) $audits->json('data.mssl_audits.0.score_percent'))->toBe(85.0);

    $italian = hseTenant('IT');
    hseActAs($italian, 'hse');
    $this->postJson('/api/v1/hse/audits/mssl', ['audit_date' => '2027-06-01', 'answers' => array_fill(1, 10, 'yes')])->assertUnprocessable();
});

it('numbers the DVR revisions and signs a revision only when all four signatories signed', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');
    $sign = fn (string $who): array => ['signed_by' => $who, 'signed_at' => '2027-06-01'];

    $this->postJson('/api/v1/hse/audits/dvr', ['issued_at' => '2027-06-01', 'signatures' => ['employer' => $sign('A'), 'rspp' => $sign('B')]])
        ->assertCreated()->assertJsonPath('data.revision_number', 1)->assertJsonPath('data.status', 'draft');
    $this->postJson('/api/v1/hse/audits/dvr', ['issued_at' => '2027-07-01', 'signatures' => ['employer' => $sign('A'), 'rspp' => $sign('B'), 'rls' => $sign('C'), 'medico_competente' => $sign('D')]])
        ->assertCreated()->assertJsonPath('data.revision_number', 2)->assertJsonPath('data.status', 'signed');
    $this->postJson('/api/v1/hse/audits/dvr', ['issued_at' => '2027-07-01', 'signatures' => ['janitor' => $sign('E')]])->assertUnprocessable();

    $swiss = hseTenant('CH');
    hseActAs($swiss, 'hse');
    $this->postJson('/api/v1/hse/audits/dvr', ['issued_at' => '2027-07-01'])->assertUnprocessable();
});

it('keeps the HSE data of one tenant away from another', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'hse');
    $employee = hseEmployee($tenant);
    hseCertificate($tenant, $employee, CertificateType::FirstAid, '2027-01-01', '2030-01-01');
    hseMedicalVisit($tenant, $employee, FitnessOutcome::Fit, '2027-01-01');

    $other = hseTenant('IT');
    hseActAs($other, 'hse');

    $this->getJson('/api/v1/hse/certificates')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/hse/medical-visits')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/hse/compliance-matrix')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps the module closed to roles without access', function (): void {
    $tenant = hseTenant('IT');
    hseActAs($tenant, 'sales');

    $this->getJson('/api/v1/hse/compliance-matrix')->assertForbidden();
    $this->postJson('/api/v1/hse/incidents', ['incident_type' => 'near_miss', 'incident_date' => '2027-05-05T08:00:00+02:00', 'description' => 'x'])->assertForbidden();
});
