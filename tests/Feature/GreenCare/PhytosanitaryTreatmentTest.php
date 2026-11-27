<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

require_once __DIR__.'/GreenCareSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    gcSetUp($this);
    $this->site = gcSite($this);
    $this->crew = gcCrew($this);
    $this->operator = gcOperator($this->crew);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gcTreatmentPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'site_id' => $test->site->id,
        'treatment_date' => '2026-05-04',
        'target_pest_or_weed' => 'Oidio',
        'treated_crop_or_surface' => 'Siepe di lauroceraso',
        'treated_area_sqm' => 500,
        'commercial_product_name' => 'Fungicida X',
        'registration_number' => '12345',
        'active_ingredient' => 'Zolfo',
        'dosage_used' => 2,
        'dosage_unit' => 'l/ha',
        'operator_id' => $test->operator->id,
        're_entry_interval_hours' => 24,
    ], $overrides);
}

test('an operator with a valid PAN license writes the treatment in the diary', function (): void {
    $license = gcLicense($this, $this->operator);

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertCreated()
        ->assertJsonPath('data.operator_license_id', $license->id)
        ->assertJsonPath('data.operator_license_type', 'PAN')
        ->assertJsonPath('data.jurisdiction', 'IT')
        ->assertJsonPath('data.re_entry_interval_hours', 24);

    expect(PhytosanitaryTreatmentLog::query()->count())->toBe(1);
});

test('an operator without any license is refused with a domain error', function (): void {
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertStatus(422)
        ->assertJsonPath('code', 'operator_license_missing');

    expect(PhytosanitaryTreatmentLog::query()->count())->toBe(0);
});

test('an expired license is refused, the expiry day is still valid', function (): void {
    gcLicense($this, $this->operator, ['issue_date' => '2020-01-01', 'expiry_date' => '2026-05-03']);

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertStatus(422)
        ->assertJsonPath('code', 'operator_license_expired');

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['treatment_date' => '2026-05-03']))->assertCreated();
});

test('a license that is not active or not issued yet does not authorise the treatment', function (): void {
    gcLicense($this, $this->operator, ['is_active' => false]);
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertStatus(422)->assertJsonPath('code', 'operator_license_missing');

    gcLicense($this, $this->operator, ['license_number' => 'PAN-2', 'issue_date' => '2026-06-01', 'expiry_date' => '2031-06-01']);
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertStatus(422)->assertJsonPath('code', 'operator_license_expired');
});

test('a Swiss license does not authorise a treatment on an Italian site, and the other way round', function (): void {
    gcLicense($this, $this->operator, ['license_type' => 'PER', 'jurisdiction' => 'CH', 'license_number' => 'PER-ZH-77']);

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertStatus(422)->assertJsonPath('code', 'operator_license_missing');

    $swiss = gcSite($this, ['jurisdiction' => 'CH']);
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['site_id' => $swiss->id, 'registration_number' => 'W-6004']))->assertCreated()
        ->assertJsonPath('data.operator_license_type', 'PER')
        ->assertJsonPath('data.jurisdiction', 'CH');

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['site_id' => $swiss->id, 'registration_number' => '12345']))->assertStatus(422)
        ->assertJsonPath('code', 'registration_number_invalid');
});

test('the registration number must have the shape of the authority of the jurisdiction', function (): void {
    gcLicense($this, $this->operator);

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['registration_number' => 'W-6004']))->assertStatus(422)
        ->assertJsonPath('code', 'registration_number_invalid');
});

test('the product used leaves the warehouse and the quantity follows the dose', function (): void {
    gcLicense($this, $this->operator);
    $warehouse = tfrWarehouse($this->tenant->id, 'FITO', ['is_primary' => true]);
    $product = tfrProduct($this->tenant->id, 'FUNG-01', 30.0, 0.0);

    // 2 l/ha over 5000 m2 = 1.0 litre.
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['treated_area_sqm' => 5000, 'warehouse_product_id' => $product->id, 'warehouse_id' => $warehouse->id]))->assertCreated()
        ->assertJsonPath('data.quantity_deducted', 1)
        ->assertJsonPath('data.warehouse_id', $warehouse->id);

    // 150 ml/hl in 200 litres of mixture = 0.3 litre; the water volume becomes mandatory.
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['dosage_unit' => 'ml/hl', 'dosage_used' => 150, 'warehouse_product_id' => $product->id, 'warehouse_id' => $warehouse->id]))
        ->assertStatus(422)->assertJsonValidationErrors(['water_volume_liters']);

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['dosage_unit' => 'ml/hl', 'dosage_used' => 150, 'water_volume_liters' => 200, 'warehouse_product_id' => $product->id, 'warehouse_id' => $warehouse->id]))->assertCreated()
        ->assertJsonPath('data.quantity_deducted', 0.3);
});

test('the diary is immutable: no endpoint changes it and the model refuses updates and deletes', function (): void {
    gcLicense($this, $this->operator);
    $id = $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertCreated()->json('data.id');

    $this->putJson(GC_API.'/treatments/'.$id, ['notes' => 'x'])->assertStatus(405);
    $this->deleteJson(GC_API.'/treatments/'.$id)->assertStatus(405);

    $log = PhytosanitaryTreatmentLog::query()->findOrFail($id);
    expect(fn () => $log->update(['notes' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

test('the live check tells whether the operator may treat on a date', function (): void {
    $this->postJson(GC_API.'/treatments/validate-license', ['operator_id' => $this->operator->id, 'treatment_date' => '2026-05-04', 'site_id' => $this->site->id])->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.reason', 'operator_license_missing');

    gcLicense($this, $this->operator, ['expiry_date' => '2026-12-31']);

    $this->postJson(GC_API.'/treatments/validate-license', ['operator_id' => $this->operator->id, 'treatment_date' => '2026-05-04', 'site_id' => $this->site->id])->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.license.expiry_date', '2026-12-31')
        ->assertJsonPath('data.license.license_type', 'PAN');

    $this->postJson(GC_API.'/treatments/validate-license', ['operator_id' => $this->operator->id, 'treatment_date' => '2027-01-02', 'site_id' => $this->site->id])->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.reason', 'operator_license_expired');
});

test('a treatment cannot be dated in the future', function (): void {
    gcLicense($this, $this->operator);

    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this, ['treatment_date' => '2026-05-05']))->assertStatus(422)->assertJsonValidationErrors(['treatment_date']);
});

test('the inspection PDF follows the filters and is a PDF file', function (): void {
    gcLicense($this, $this->operator);
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertCreated();

    $response = $this->get(GC_API.'/treatments/export-pdf?from=2026-05-01&to=2026-05-31&site_id='.$this->site->id.'&operator_id='.$this->operator->id);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');

    $this->getJson(GC_API.'/treatments/export-pdf?from=2026-06-01&to=2026-05-01')->assertStatus(422);
});

test('the diary of another company is invisible', function (): void {
    gcLicense($this, $this->operator);
    $this->postJson(GC_API.'/treatments', gcTreatmentPayload($this))->assertCreated();

    billingActor($this, 'accountant', $this->otherTenant);

    $this->getJson(GC_API.'/treatments')->assertOk()->assertJsonPath('meta.total', 0);
});
