<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ExpenseManagement\Models\MileageClaim;

require_once __DIR__.'/ExpenseManagementSupport.php';

uses(RefreshDatabase::class);

it('quotes and books a mileage claim at the ACI rate of the vehicle (IT), doubling a round trip', function (): void {
    exmSetUp($this);
    $report = exmCreateReport($this);

    $quote = $this->postJson('/api/v1/expense-management/mileage-claims/quote', ['vehicle_class_code' => 'VW-GOLF-TDI', 'distance_km' => '100', 'is_round_trip' => true, 'on_date' => now()->toDateString(), 'annual_km_bracket' => 15000])->assertOk();
    expect($quote->json('data.calculation_source'))->toBe('IT_ACI_TABLE')
        ->and($quote->json('data.total_km'))->toBe('200.00')
        ->and(bcmul($quote->json('data.rate_per_km'), '200', 2))->toBe($quote->json('data.calculated_amount'));

    $claim = $this->postJson("/api/v1/expense-management/reports/{$report}/mileage-claims", [
        'expense_date' => now()->subDay()->toDateString(), 'origin_address' => 'Torino', 'destination_address' => 'Milano', 'distance_km' => '100', 'is_round_trip' => true,
        'vehicle_class_code' => 'VW-GOLF-TDI', 'annual_km_bracket' => 15000,
    ])->assertCreated();

    expect($claim->json('data.mileage.calculated_amount'))->toBe($quote->json('data.calculated_amount'))
        ->and($claim->json('data.category.code'))->toBe('mileage')
        ->and(MileageClaim::query()->count())->toBe(1);
    expect($this->getJson("/api/v1/expense-management/reports/{$report}")->json('data.total_amount'))->toBe($quote->json('data.calculated_amount'));

    $this->postJson('/api/v1/expense-management/mileage-claims/quote', ['vehicle_class_code' => 'UNKNOWN', 'distance_km' => '10'])->assertStatus(422)->assertJsonPath('code', 'vehicle_rate_not_found');
});

it('removes the line with its mileage claim', function (): void {
    exmSetUp($this);
    $report = exmCreateReport($this);
    $claim = $this->postJson("/api/v1/expense-management/reports/{$report}/mileage-claims", [
        'expense_date' => now()->toDateString(), 'origin_address' => 'A', 'destination_address' => 'B', 'distance_km' => '10', 'vehicle_class_code' => 'FIAT-PANDA-HYBRID',
    ])->assertCreated()->json('data.mileage.id');

    $this->deleteJson("/api/v1/expense-management/reports/{$report}/mileage-claims/{$claim}")->assertOk();
    expect(MileageClaim::query()->count())->toBe(0)
        ->and($this->getJson("/api/v1/expense-management/reports/{$report}")->json('data.total_amount'))->toBe('0.00');
});

it('uses CHF 0.70 per km under the Swiss regulation and the TCS table when asked (CH)', function (): void {
    exmSetUp($this, 'CH');

    $regulation = $this->postJson('/api/v1/expense-management/mileage-claims/quote', ['vehicle_class_code' => 'ANY', 'distance_km' => '100'])->assertOk();
    expect($regulation->json('data.calculation_source'))->toBe('CH_SPESENREGLEMENT')->and($regulation->json('data.rate_per_km'))->toBe('0.7000')->and($regulation->json('data.calculated_amount'))->toBe('70.00');

    $tcs = $this->postJson('/api/v1/expense-management/mileage-claims/quote', ['vehicle_class_code' => 'TCS-COMPACT', 'distance_km' => '100', 'calculation_source' => 'CH_TCS'])->assertOk();
    expect($tcs->json('data.calculation_source'))->toBe('CH_TCS')->and($tcs->json('data.rate_per_km'))->toBe('0.6800');
});

it('lists the vehicle rates with filters', function (): void {
    exmSetUp($this);

    $this->getJson('/api/v1/expense-management/aci-rates?source=IT_ACI_TABLE&vehicle_code=VW-GOLF-TDI')->assertOk()->assertJsonCount(6, 'data');
    $this->getJson('/api/v1/expense-management/aci-rates?source=CH_TCS')->assertOk()->assertJsonCount(2, 'data');
});

it('computes the Italian per-diem under the three regimes (Art. 51 c.5 TUIR)', function (): void {
    exmSetUp($this);
    $base = ['country_code' => 'IT', 'destination_type' => 'domestic', 'trip_start_at' => '2028-03-01 07:00:00', 'trip_end_at' => '2028-03-02 20:00:00', 'allowance_rate_per_day' => '50.00'];

    $flat = $this->postJson('/api/v1/expense-management/per-diems/preview', $base + ['allowance_method' => 'flat_rate'])->assertOk();
    expect($flat->json('data.days_count'))->toBe('2.00')->and($flat->json('data.gross_allowance_amount'))->toBe('100.00')->and($flat->json('data.tax_free_amount'))->toBe('92.96')->and($flat->json('data.taxable_excess_amount'))->toBe('7.04');

    $mixed = $this->postJson('/api/v1/expense-management/per-diems/preview', $base + ['allowance_method' => 'mixed', 'lodging_reimbursed' => true, 'meals_reimbursed' => true])->assertOk();
    expect($mixed->json('data.daily_tax_free_limit'))->toBe('15.50')->and($mixed->json('data.taxable_excess_amount'))->toBe('69.00');

    $abroad = $this->postJson('/api/v1/expense-management/per-diems/preview', array_merge($base, ['country_code' => 'FR', 'destination_type' => 'international', 'allowance_method' => 'flat_rate']))->assertOk();
    expect($abroad->json('data.daily_tax_free_limit'))->toBe('77.47')->and($abroad->json('data.taxable_excess_amount'))->toBe('0.00');

    $municipality = $this->postJson('/api/v1/expense-management/per-diems/preview', array_merge($base, ['destination_type' => 'municipality', 'allowance_method' => 'flat_rate']))->assertOk();
    expect($municipality->json('data.tax_free_amount'))->toBe('0.00')->and($municipality->json('data.taxable_excess_amount'))->toBe('100.00');

    $short = $this->postJson('/api/v1/expense-management/per-diems/preview', array_merge($base, ['trip_end_at' => '2028-03-01 12:00:00', 'allowance_method' => 'flat_rate']))->assertOk();
    expect($short->json('data.days_count'))->toBe('0.00')->and($short->json('data.gross_allowance_amount'))->toBe('0.00');
});

it('stores a per-diem on the report and counts its taxable excess in the taxable amount', function (): void {
    exmSetUp($this);
    $report = exmCreateReport($this);

    $allowance = $this->postJson("/api/v1/expense-management/reports/{$report}/per-diems", [
        'country_code' => 'IT', 'destination_type' => 'domestic', 'allowance_method' => 'flat_rate', 'trip_start_at' => '2028-03-01 07:00:00', 'trip_end_at' => '2028-03-01 20:00:00', 'allowance_rate_per_day' => '60.00',
    ])->assertCreated();
    expect($allowance->json('data.taxable_excess_amount'))->toBe('13.52');

    $detail = $this->getJson("/api/v1/expense-management/reports/{$report}")->json('data');
    expect($detail['total_amount'])->toBe('60.00')->and($detail['taxable_amount'])->toBe('13.52')->and($detail['per_diems'])->toHaveCount(1);

    $this->deleteJson("/api/v1/expense-management/reports/{$report}/per-diems/{$allowance->json('data.id')}")->assertOk();
    expect($this->getJson("/api/v1/expense-management/reports/{$report}")->json('data.total_amount'))->toBe('0.00');
});

it('applies the Swiss regulation limit to the per-diem (CH)', function (): void {
    exmSetUp($this, 'CH');

    $result = $this->postJson('/api/v1/expense-management/per-diems/preview', [
        'country_code' => 'CH', 'destination_type' => 'domestic', 'allowance_method' => 'flat_rate', 'trip_start_at' => '2028-03-01 07:00:00', 'trip_end_at' => '2028-03-01 20:00:00', 'allowance_rate_per_day' => '150.00',
    ])->assertOk();

    expect($result->json('data.daily_tax_free_limit'))->toBe('120.00')->and($result->json('data.taxable_excess_amount'))->toBe('30.00');
});
