<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;

require_once __DIR__.'/ForeignVatSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fvRulePayload(array $overrides = []): array
{
    return array_merge([
        'home_country_code' => 'IT', 'refund_country_code' => 'DE', 'regime_type' => 'EU_DIR_2008_9_CE', 'has_reciprocity' => true,
        'expense_category_code' => 6, 'deductible_percentage' => 50, 'min_annual_amount' => 50, 'min_quarterly_amount' => 400,
        'currency_code' => 'EUR', 'filing_deadline_month' => 9, 'filing_deadline_day' => 30,
    ], $overrides);
}

test('the reference data lists the countries, the categories and the regimes', function (): void {
    fvSetUp($this);

    $this->getJson(FVR_API.'/reference')->assertOk()->assertJsonStructure(['data' => ['home_country', 'countries', 'categories', 'regimes']]);
});

test('the rules endpoint returns the global matrix of the home country', function (): void {
    fvSetUp($this);

    $response = $this->getJson(FVR_API.'/rules?country=DE')->assertOk()->assertJsonPath('meta.home_country', 'IT');
    $rules = collect($response->json('data'));

    expect($rules)->toHaveCount(10)->and($rules->every(fn (array $rule): bool => $rule['is_global'] === true && $rule['refund_country_code'] === 'DE'))->toBeTrue();
});

test('a company overrides one cell, changes it and reverts to the global rule', function (): void {
    fvSetUp($this);
    $global = collect($this->getJson(FVR_API.'/rules?country=DE')->json('data'))->firstWhere('expense_category_code', 6);

    $created = $this->postJson(FVR_API.'/rules', fvRulePayload(['deductible_percentage' => 30]))->assertCreated()->assertJsonPath('data.is_global', false)->assertJsonPath('data.deductible_percentage', '30.00');
    $overridden = collect($this->getJson(FVR_API.'/rules?country=DE')->json('data'))->firstWhere('expense_category_code', 6);
    expect($overridden['id'])->toBe($created->json('data.id'))->and($overridden['deductible_percentage'])->toBe('30.00');

    $this->putJson(FVR_API.'/rules/'.$created->json('data.id'), fvRulePayload(['deductible_percentage' => 10]))->assertOk()->assertJsonPath('data.deductible_percentage', '10.00');
    $this->deleteJson(FVR_API.'/rules/'.$created->json('data.id'))->assertOk();

    $back = collect($this->getJson(FVR_API.'/rules?country=DE')->json('data'))->firstWhere('expense_category_code', 6);
    expect($back['id'])->toBe($global['id'])->and($back['is_global'])->toBeTrue();
});

test('the override changes the split of the next line', function (): void {
    fvSetUp($this);
    $this->postJson(FVR_API.'/rules', fvRulePayload(['deductible_percentage' => 50]))->assertCreated();

    $entry = fvEntry($this, ['supplier_country_code' => 'DE', 'expense_category_code' => 6, 'foreign_vat_amount' => 190]);

    expect((string) $entry->recoverable_vat_amount)->toBe('95.00')->and((string) $entry->non_recoverable_vat_amount)->toBe('95.00');
});

test('global rules cannot be edited or removed by a company', function (): void {
    fvSetUp($this);
    $global = CountryVatRefundRule::query()->whereNull('tenant_id')->where('refund_country_code', 'DE')->firstOrFail();

    $this->putJson(FVR_API.'/rules/'.$global->id, fvRulePayload())->assertForbidden();
    $this->deleteJson(FVR_API.'/rules/'.$global->id)->assertForbidden();
    expect(CountryVatRefundRule::query()->whereKey($global->id)->exists())->toBeTrue();
});

test('an invalid rule is refused', function (): void {
    fvSetUp($this);

    $this->postJson(FVR_API.'/rules', fvRulePayload(['refund_country_code' => 'IT']))->assertUnprocessable()->assertJsonValidationErrors('refund_country_code');
    $this->postJson(FVR_API.'/rules', fvRulePayload(['deductible_percentage' => 150]))->assertUnprocessable()->assertJsonValidationErrors('deductible_percentage');
    $this->postJson(FVR_API.'/rules', fvRulePayload(['expense_category_code' => 99]))->assertUnprocessable()->assertJsonValidationErrors('expense_category_code');
});

test('the overrides of a company do not reach another company', function (): void {
    fvSetUp($this);
    $this->postJson(FVR_API.'/rules', fvRulePayload(['deductible_percentage' => 30]))->assertCreated();

    treasuryActor($this, 'accountant', $this->otherTenant);
    $rule = collect($this->getJson(FVR_API.'/rules?country=DE')->json('data'))->firstWhere('expense_category_code', 6);

    expect($rule['is_global'])->toBeTrue()->and($rule['deductible_percentage'])->not->toBe('30.00');
});

test('mandates are created, updated, listed and removed with their documents', function (): void {
    fvSetUp($this);

    $created = $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'representative_tax_id' => 'CHE-123.456.788 MWST', 'mandate_start_date' => '2026-01-01'])->assertCreated()
        ->assertJsonPath('data.country_code', 'CH');
    $id = $created->json('data.id');

    $this->post(FVR_API.'/mandates/'.$id.'/documents', ['file' => UploadedFile::fake()->create('poa.pdf', 20, 'application/pdf'), 'type' => 'power_of_attorney'])->assertOk();
    $this->post(FVR_API.'/mandates/'.$id.'/documents', ['file' => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'), 'type' => 'certificate', 'certificate_expiry_date' => '2028-01-01'])->assertOk();
    $this->get(FVR_API.'/mandates/'.$id.'/documents/certificate')->assertOk();
    $this->get(FVR_API.'/mandates/'.$id.'/documents/power_of_attorney')->assertOk();

    $this->putJson(FVR_API.'/mandates/'.$id, ['country_code' => 'CH', 'representative_name' => 'Treuhand Zurich AG', 'mandate_start_date' => '2026-01-01', 'certificate_expiry_date' => '2028-06-30'])->assertOk()
        ->assertJsonPath('data.representative_name', 'Treuhand Zurich AG');
    $this->getJson(FVR_API.'/mandates')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(FVR_API.'/mandates/'.$id)->assertOk()->assertJsonPath('data.id', $id);

    $this->deleteJson(FVR_API.'/mandates/'.$id)->assertOk();
    $this->getJson(FVR_API.'/mandates')->assertOk()->assertJsonCount(0, 'data');
});

test('a mandate document must be a file of an allowed type', function (): void {
    fvSetUp($this);
    $id = $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'mandate_start_date' => '2026-01-01'])->assertCreated()->json('data.id');

    $this->post(FVR_API.'/mandates/'.$id.'/documents', ['file' => UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload'), 'type' => 'certificate'], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post(FVR_API.'/mandates/'.$id.'/documents', ['type' => 'certificate'], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('the mandates of a company are invisible to another company', function (): void {
    fvSetUp($this);
    $id = $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'mandate_start_date' => '2026-01-01'])->assertCreated()->json('data.id');

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson(FVR_API.'/mandates')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(FVR_API.'/mandates/'.$id)->assertNotFound();
    $this->deleteJson(FVR_API.'/mandates/'.$id)->assertNotFound();
});

test('the settings are read and updated', function (): void {
    fvSetUp($this);

    $this->getJson(FVR_API.'/settings')->assertOk();
    $this->putJson(FVR_API.'/settings', ['auto_intercept' => false])->assertOk();
});

test('roles without the finance permission cannot change rules or mandates', function (): void {
    fvSetUp($this, 'IT', 'sales');

    $this->postJson(FVR_API.'/rules', fvRulePayload())->assertForbidden();
    $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'X', 'mandate_start_date' => '2026-01-01'])->assertForbidden();
});
