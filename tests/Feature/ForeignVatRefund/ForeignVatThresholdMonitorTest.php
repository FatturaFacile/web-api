<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Services\ThresholdMonitor;

require_once __DIR__.'/ForeignVatSupport.php';

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>|null
 */
function fvMonitorRow(object $test, string $country, int $year = 2026, ?string $today = null): ?array
{
    $rows = app(ThresholdMonitor::class)->overview((string) $test->tenant->id, CarbonImmutable::parse($today ?? '2027-07-10'));

    return collect($rows)->first(fn (array $row): bool => $row['country_code'] === $country && $row['fiscal_year'] === $year);
}

test('the monitor accumulates the recoverable VAT per country and year against the EU thresholds', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2026-02-10', 'foreign_vat_amount' => 190, 'net_amount' => 1000]);
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2026-05-10', 'foreign_vat_amount' => 95, 'net_amount' => 500]);
    fvEntry($this, ['supplier_country_code' => 'DE', 'expense_category_code' => 9, 'document_date' => '2026-05-11', 'foreign_vat_amount' => 500, 'net_amount' => 2500]);

    $row = fvMonitorRow($this, 'DE');

    expect($row)->not->toBeNull()
        ->and($row['regime_type'])->toBe('EU_DIR_2008_9_CE')
        ->and($row['accumulated_amount'])->toBe('285.00')
        ->and($row['threshold_annual'])->toBe('50.00')
        ->and($row['threshold_quarterly'])->toBe('400.00')
        ->and($row['eligibility_status'])->toBe('THRESHOLD_MET')
        ->and($row['progress_percent'])->toBe('100.00')
        ->and($row['items_count'])->toBe(2)
        ->and($row['deadline_date'])->toBe('2027-09-30')
        ->and($row['days_remaining'])->toBe(82)
        ->and($row['alert_level'])->toBeNull()
        ->and(collect($row['quarters'])->pluck('amount', 'quarter')->all())->toBe([1 => '190.00', 2 => '95.00', 3 => '0.00', 4 => '0.00'])
        ->and($row['quarters'][0]['eligibility_status'])->toBe('BELOW_THRESHOLD')
        ->and($row['quarters'][0]['progress_percent'])->toBe('47.50');
});

test('an amount under the annual minimum is below threshold', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'AT', 'foreign_vat_amount' => 40, 'net_amount' => 200, 'foreign_vat_rate' => 20]);

    $row = fvMonitorRow($this, 'AT');

    expect($row['accumulated_amount'])->toBe('40.00')->and($row['eligibility_status'])->toBe('BELOW_THRESHOLD')->and($row['progress_percent'])->toBe('80.00');
});

test('the Swiss ESTV procedure has a CHF 500 annual minimum, the 30 June deadline and requires the representative and the certificate', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'net_amount' => 10000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 380, 'exchange_rate_to_base' => 1.05]);

    $row = fvMonitorRow($this, 'CH', 2026, '2027-05-01');

    expect($row['regime_type'])->toBe('CH_ESTV_RECIPROCITY')
        ->and($row['currency_code'])->toBe('CHF')
        ->and($row['threshold_annual'])->toBe('500.00')
        ->and($row['threshold_quarterly'])->toBeNull()
        ->and($row['quarters'])->toBe([])
        ->and($row['eligibility_status'])->toBe('BELOW_THRESHOLD')
        ->and($row['progress_percent'])->toBe('76.00')
        ->and($row['deadline_date'])->toBe('2027-06-30')
        ->and($row['days_remaining'])->toBe(60)
        ->and($row['alert_level'])->toBe(60)
        ->and($row['requires_fiscal_representative'])->toBeTrue()
        ->and($row['requires_taxable_status_certificate'])->toBeTrue()
        ->and($row['representative']['status'])->toBe('MISSING');
});

test('the alert level follows the 60, 30 and 15 day marks and expires after the deadline', function (): void {
    fvSetUp($this);
    $monitor = app(ThresholdMonitor::class);

    expect($monitor->alertLevel(120))->toBeNull()
        ->and($monitor->alertLevel(61))->toBeNull()
        ->and($monitor->alertLevel(60))->toBe(60)
        ->and($monitor->alertLevel(31))->toBe(60)
        ->and($monitor->alertLevel(30))->toBe(30)
        ->and($monitor->alertLevel(16))->toBe(30)
        ->and($monitor->alertLevel(15))->toBe(15)
        ->and($monitor->alertLevel(0))->toBe(15)
        ->and($monitor->alertLevel(-1))->toBe('EXPIRED');

    fvEntry($this, ['supplier_country_code' => 'DE']);

    expect(fvMonitorRow($this, 'DE', 2026, '2027-09-20')['alert_level'])->toBe(15)
        ->and(fvMonitorRow($this, 'DE', 2026, '2027-10-01')['alert_level'])->toBe('EXPIRED')
        ->and(fvMonitorRow($this, 'DE', 2026, '2027-10-01')['days_remaining'])->toBe(-1);
});

test('the certificate of taxable status is tracked: valid, expiring and expired', function (): void {
    fvSetUp($this);
    $mandate = FiscalRepresentativeMandate::create(['tenant_id' => $this->tenant->id, 'country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'mandate_start_date' => '2026-01-01', 'is_active' => true]);
    $monitor = app(ThresholdMonitor::class);
    $today = CarbonImmutable::parse('2027-07-10');
    $status = fn (): string => $monitor->mandateStatus((string) $this->tenant->id, 'CH', $today, true, true)['status'];

    expect($status())->toBe('CERTIFICATE_MISSING');

    $mandate->update(['certificate_of_taxable_status_path' => 'x/cert.pdf', 'certificate_expiry_date' => '2027-07-01']);
    expect($status())->toBe('CERTIFICATE_EXPIRED');

    $mandate->update(['certificate_expiry_date' => '2027-08-15']);
    expect($status())->toBe('CERTIFICATE_EXPIRING');

    $mandate->update(['certificate_expiry_date' => '2028-07-10']);
    expect($status())->toBe('VALID')
        ->and($monitor->mandateStatus((string) $this->tenant->id, 'DE', $today, false, false)['status'])->toBe('NOT_REQUIRED')
        ->and($monitor->mandateStatus((string) $this->tenant->id, 'FR', $today, true, false)['status'])->toBe('MISSING');
});

test('the dashboard endpoint carries the KPI cards and the monitor and stays inside the company', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2026-02-10']);

    $response = $this->getJson(FVR_API.'/dashboard')->assertOk();

    $response->assertJsonPath('data.kpis.accumulating.0.currency', 'EUR')
        ->assertJsonPath('data.kpis.accumulating.0.amount', '190.00')
        ->assertJsonPath('data.kpis.ready_to_claim.0.amount', '190.00')
        ->assertJsonPath('data.kpis.submitted_awaiting_refund', [])
        ->assertJsonPath('data.kpis.refunded_ytd', [])
        ->assertJsonPath('data.monitor.0.country_code', 'DE');

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(FVR_API.'/dashboard')->assertOk()->assertJsonPath('data.monitor', [])->assertJsonPath('data.kpis.accumulating', []);
});

test('only finance roles write and the module access rules keep other roles out', function (): void {
    fvSetUp($this, 'IT', 'sales');

    $this->getJson(FVR_API.'/dashboard')->assertForbidden();
    $this->postJson(FVR_API.'/ledger', fvManualPayload())->assertForbidden();
});
