<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\Enums\BlockingReasonCode;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\ValueObjects\BlockingReason;
use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $this->tenant = hseTenant();
    $this->position = (string) Str::uuid();
    $this->employee = hseEmployee($this->tenant, ['position_id' => null]);
    $this->employee->forceFill(['position_id' => null])->save();
    $this->at = CarbonImmutable::parse('2027-06-15 08:00:00');
});

/**
 * @return list<string>
 */
function hseReasonCodes(object $test, string $context, string $targetId, ?CarbonImmutable $at = null): array
{
    $result = app(HseClearanceGatekeeperInterface::class)->evaluate(new ClearanceRequest(
        (string) $test->tenant->id, (string) $test->employee->id, ClearanceContext::from($context), $targetId, $at ?? $test->at,
    ));

    return array_map(static fn (BlockingReason $reason): string => $reason->code->value, $result->blockingReasons);
}

it('clears a worker when nothing is required', function (): void {
    expect(hseReasonCodes($this, 'production_work_center', (string) Str::uuid()))->toBe([]);
});

it('blocks a dispatch to every module context when a required certificate is missing', function (string $context): void {
    $target = (string) Str::uuid();
    hseMatrix($this->tenant, [
        'job_position_id' => null, 'work_center_id' => $context === 'construction_site' ? null : $target, 'construction_site_id' => $context === 'construction_site' ? $target : null,
        'required_training_types_json' => [['type' => CertificateType::Forklift->value]],
    ]);

    expect(hseReasonCodes($this, $context, $target))->toBe([BlockingReasonCode::CertificateMissing->value]);

    hseCertificate($this->tenant, $this->employee, CertificateType::Forklift, '2026-01-01', '2031-01-01');

    expect(hseReasonCodes($this, $context, $target))->toBe([]);
})->with(['production_work_center', 'external_field_service', 'construction_site']);

it('blocks an expired certificate as of the scheduled moment, not as of today', function (): void {
    $target = (string) Str::uuid();
    hseMatrix($this->tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_training_types_json' => [['type' => CertificateType::FirstAid->value]]]);
    hseCertificate($this->tenant, $this->employee, CertificateType::FirstAid, '2024-07-01', '2027-07-01');

    expect(hseReasonCodes($this, 'production_work_center', $target))->toBe([])
        ->and(hseReasonCodes($this, 'production_work_center', $target, CarbonImmutable::parse('2027-07-02')))->toBe([BlockingReasonCode::CertificateExpired->value]);
});

it('does not apply a rule scoped to another work center', function (): void {
    hseMatrix($this->tenant, ['job_position_id' => null, 'work_center_id' => (string) Str::uuid(), 'required_training_types_json' => [['type' => CertificateType::Forklift->value]]]);

    expect(hseReasonCodes($this, 'production_work_center', (string) Str::uuid()))->toBe([]);
});

it('requires every scope of a rule: position and site together', function (): void {
    $site = (string) Str::uuid();
    $position = (string) Str::uuid();
    $this->employee->forceFill(['position_id' => null])->save();
    hseMatrix($this->tenant, ['job_position_id' => $position, 'construction_site_id' => $site, 'required_training_types_json' => [['type' => CertificateType::WorkAtHeight->value]]]);

    expect(hseReasonCodes($this, 'construction_site', $site))->toBe([]);
});

it('blocks a worker judged temporarily or permanently unfit even without a matching rule', function (FitnessOutcome $outcome): void {
    hseMedicalVisit($this->tenant, $this->employee, $outcome, '2027-05-01');

    expect(hseReasonCodes($this, 'production_work_center', (string) Str::uuid()))->toBe([BlockingReasonCode::MedicalUnfit->value]);
})->with([FitnessOutcome::TemporarilyUnfit, FitnessOutcome::PermanentlyUnfit]);

it('blocks a missing or overdue medical judgment when a rule asks for the exam', function (): void {
    $target = (string) Str::uuid();
    hseMatrix($this->tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_medical_exam_codes_json' => ['PERIODIC_GENERAL']]);

    expect(hseReasonCodes($this, 'production_work_center', $target))->toBe([BlockingReasonCode::MedicalMissing->value]);

    hseMedicalVisit($this->tenant, $this->employee, FitnessOutcome::Fit, '2025-05-01', '2027-05-01');

    expect(hseReasonCodes($this, 'production_work_center', $target))->toBe([BlockingReasonCode::MedicalExpired->value]);
});

it('returns the prescriptions as warnings and blocks only a prescription that conflicts with the work', function (): void {
    $target = (string) Str::uuid();
    hseMatrix($this->tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_training_types_json' => [['type' => CertificateType::WorkAtHeight->value]]]);
    hseCertificate($this->tenant, $this->employee, CertificateType::WorkAtHeight, '2026-01-01', '2031-01-01');
    hseMedicalVisit($this->tenant, $this->employee, FitnessOutcome::FitWithPrescriptions, '2027-05-01', null, 'No manual lifting over 10 kg');

    $result = app(HseClearanceGatekeeperInterface::class)->evaluate(new ClearanceRequest((string) $this->tenant->id, (string) $this->employee->id, ClearanceContext::ProductionWorkCenter, $target, $this->at));
    expect($result->isCleared)->toBeTrue()->and($result->warnings)->toBe(['No manual lifting over 10 kg']);

    hseMedicalVisit($this->tenant, $this->employee, FitnessOutcome::FitWithPrescriptions, '2027-06-01', null, 'No work at height [BLOCK:'.CertificateType::WorkAtHeight->value.']');

    expect(hseReasonCodes($this, 'production_work_center', $target))->toBe([BlockingReasonCode::MedicalRestrictionConflict->value]);
});

it('blocks a missing or overdue mandatory PPE', function (): void {
    $target = (string) Str::uuid();
    $product = (string) Str::uuid();
    hseMatrix($this->tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_ppe_product_ids_json' => [$product]]);

    expect(hseReasonCodes($this, 'production_work_center', $target))->toBe([BlockingReasonCode::PpeMissing->value]);

    PpeDistributionRecord::create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'product_id' => $product, 'warehouse_id' => (string) Str::uuid(), 'quantity' => 1,
        'handed_over_at' => '2026-06-01 08:00:00', 'replacement_due_at' => '2027-12-01', 'employee_signature_hash' => str_repeat('b', 64), 'delivery_status' => 'delivered_signed',
    ]);

    expect(hseReasonCodes($this, 'production_work_center', $target))->toBe([])
        ->and(hseReasonCodes($this, 'production_work_center', $target, CarbonImmutable::parse('2028-01-10')))->toBe([BlockingReasonCode::PpeMissing->value]);
});

it('refuses an employee of another tenant', function (): void {
    $other = hseTenant();
    $stranger = hseEmployee($other);

    app(HseClearanceGatekeeperInterface::class)->evaluate(new ClearanceRequest((string) $this->tenant->id, (string) $stranger->id, ClearanceContext::ProductionWorkCenter, 'x', $this->at));
})->throws(HseException::class);

it('exposes the verdict over the REST endpoint used by the dispatch modules', function (): void {
    hseActAs($this->tenant, 'production');
    $target = (string) Str::uuid();
    hseMatrix($this->tenant, ['job_position_id' => null, 'work_center_id' => $target, 'required_training_types_json' => [['type' => CertificateType::Forklift->value]]]);

    $this->getJson('/api/v1/hse/clearance?'.http_build_query([
        'employee_id' => $this->employee->id, 'target_context_type' => 'production_work_center', 'target_id' => $target, 'scheduled_date_time' => '2027-06-15T08:00:00+02:00',
    ]))->assertOk()
        ->assertJsonPath('data.is_cleared', false)
        ->assertJsonPath('data.blocking_reasons.0.code', 'certificate_missing')
        ->assertJsonPath('data.blocking_reasons.0.subject', 'forklift_patentino_muletto');
});

it('requires authentication on the clearance endpoint', function (): void {
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/hse/clearance')->assertUnauthorized();
});
