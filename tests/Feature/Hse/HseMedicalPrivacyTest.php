<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Infrastructure\Models\EmployeeClearanceCache;
use Modules\Hse\Infrastructure\Models\MedicalClinicalVaultEntry;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;

require_once __DIR__.'/HseTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $this->tenant = hseTenant('IT');
    $this->employee = hseEmployee($this->tenant);
    $this->payload = [
        'employee_id' => (string) $this->employee->id, 'doctor_name' => 'Dr. Bianchi', 'doctor_registration_number' => 'OMCEO-123', 'visit_type' => 'periodic',
        'visit_date' => '2027-06-01', 'next_visit_due_date' => '2028-06-01', 'fitness_outcome' => 'fit_with_prescriptions_idoneo_con_prescrizioni',
        'restrictions_summary' => 'No manual lifting over 10 kg', 'clinical_notes' => 'DIAGNOSIS: lumbar disc herniation L4-L5',
    ];
});

it('lets the occupational physician record the judgment with clinical notes and read them back', function (): void {
    hseActAs($this->tenant, 'medico');

    $id = $this->postJson('/api/v1/hse/medical-visits', $this->payload)->assertCreated()->json('data.id');

    $this->getJson("/api/v1/hse/medical-visits/{$id}/clinical-notes")->assertOk()->assertJsonPath('data.clinical_notes', 'DIAGNOSIS: lumbar disc herniation L4-L5');
});

it('stores the clinical notes encrypted and apart from the visit row', function (): void {
    hseActAs($this->tenant, 'medico');
    $id = $this->postJson('/api/v1/hse/medical-visits', $this->payload)->assertCreated()->json('data.id');

    $raw = (string) DB::table('hse_medical_clinical_vault')->where('medical_visit_id', $id)->value('encrypted_notes');
    $visitRow = json_encode(DB::table('hse_medical_visits')->where('id', $id)->first());

    expect($raw)->not->toContain('lumbar')->and($visitRow)->not->toContain('lumbar')
        ->and(MedicalClinicalVaultEntry::query()->where('medical_visit_id', $id)->firstOrFail()->encrypted_notes)->toContain('lumbar');
});

it('shows HR and operational users the fitness outcome and the prescriptions but never the clinical data', function (string $role): void {
    OccupationalMedicalVisit::unsetEventDispatcher();
    $visit = hseMedicalVisit($this->tenant, $this->employee, FitnessOutcome::FitWithPrescriptions, '2027-06-01', '2028-06-01', 'No manual lifting over 10 kg');
    MedicalClinicalVaultEntry::create(['tenant_id' => $this->tenant->id, 'medical_visit_id' => $visit->id, 'encrypted_notes' => 'DIAGNOSIS: secret']);
    $visit->update(['encrypted_clinical_notes_ref' => 'vault-ref-1']);
    hseActAs($this->tenant, $role);

    $response = $this->getJson('/api/v1/hse/medical-visits')->assertOk()
        ->assertJsonPath('data.0.fitness_outcome', 'fit_with_prescriptions_idoneo_con_prescrizioni')
        ->assertJsonPath('data.0.restrictions_summary', 'No manual lifting over 10 kg')
        ->assertJsonPath('data.0.has_clinical_notes', true);

    expect($response->getContent())->not->toContain('secret')->not->toContain('DIAGNOSIS')->not->toContain('vault-ref-1')->not->toContain('encrypted');
})->with(['hr', 'hse', 'production', 'member']);

it('refuses the clinical notes to HR, the HSE team, owners and admins', function (string $role): void {
    hseActAs($this->tenant, 'medico');
    $id = $this->postJson('/api/v1/hse/medical-visits', $this->payload)->assertCreated()->json('data.id');

    hseActAs($this->tenant, $role);
    $this->getJson("/api/v1/hse/medical-visits/{$id}/clinical-notes")->assertForbidden();
})->with(['hr', 'hse', 'owner', 'admin', 'production', 'member']);

it('refuses clinical notes written by anybody but the physician', function (): void {
    hseActAs($this->tenant, 'hr');

    $this->postJson('/api/v1/hse/medical-visits', $this->payload)->assertForbidden()->assertJsonPath('title', 'Forbidden Clinical Data');
    $this->postJson('/api/v1/hse/medical-visits', array_diff_key($this->payload, ['clinical_notes' => 1]))->assertCreated();

    expect(MedicalClinicalVaultEntry::query()->count())->toBe(0);
});

it('keeps clinical data out of the audit ledger payloads', function (): void {
    hseActAs($this->tenant, 'medico');
    $id = $this->postJson('/api/v1/hse/medical-visits', $this->payload)->assertCreated()->json('data.id');

    $visit = OccupationalMedicalVisit::query()->findOrFail($id);
    $vault = MedicalClinicalVaultEntry::query()->where('medical_visit_id', $id)->firstOrFail();

    expect($visit->auditPayload($visit->getAttributes()))->not->toHaveKey('encrypted_clinical_notes_ref')
        ->and($vault->auditPayload($vault->getAttributes()))->not->toHaveKey('encrypted_notes')
        ->and(array_key_exists('encrypted_clinical_notes_ref', $visit->toArray()))->toBeFalse();
});

it('updates the operational clearance cache and alerts HR for anything but a fit judgment', function (): void {
    $hr = hseActAs($this->tenant, 'hr');
    hseActAs($this->tenant, 'medico');

    $this->postJson('/api/v1/hse/medical-visits', [...$this->payload, 'fitness_outcome' => 'temporarily_unfit_inidoneo_temporaneo', 'clinical_notes' => null])->assertCreated();

    $cache = EmployeeClearanceCache::query()->where('employee_id', $this->employee->id)->firstOrFail();

    expect($cache->medical_status)->toBe('temporarily_unfit_inidoneo_temporaneo')
        ->and(DB::table('notifications')->where('notifiable_id', $hr->id)->where('data', 'like', '%hse_medical_fitness_changed%')->count())->toBe(1);
});

it('does not let an older visit entered late overwrite the clearance cache', function (): void {
    hseActAs($this->tenant, 'medico');
    $this->postJson('/api/v1/hse/medical-visits', [...$this->payload, 'visit_date' => '2027-06-01', 'fitness_outcome' => 'fit_idoneo', 'clinical_notes' => null, 'restrictions_summary' => null])->assertCreated();
    $this->postJson('/api/v1/hse/medical-visits', [...$this->payload, 'visit_date' => '2026-01-01', 'next_visit_due_date' => '2027-01-01', 'fitness_outcome' => 'permanently_unfit_inidoneo_permanente', 'clinical_notes' => null])->assertCreated();

    expect(EmployeeClearanceCache::query()->where('employee_id', $this->employee->id)->value('medical_status'))->toBe('fit_idoneo');
});
