<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;

require_once __DIR__.'/ExpenseManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    exmSetUp($this);
});

it('numbers reports per year and keeps each member to their own reports', function (): void {
    $first = exmCreateReport($this);
    $second = exmCreateReport($this);

    $numbers = collect([$first, $second])->map(fn (string $id): string => $this->getJson("/api/v1/expense-management/reports/{$id}")->json('data.report_number'))->all();
    expect($numbers)->toBe(['ER-'.now()->format('Y').'-0001', 'ER-'.now()->format('Y').'-0002']);

    exmActAs($this, 'member');
    $this->getJson('/api/v1/expense-management/reports')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/expense-management/reports/{$first}")->assertForbidden();

    exmActAs($this, 'accountant');
    $this->getJson('/api/v1/expense-management/reports')->assertOk()->assertJsonCount(2, 'data');
});

it('rejects an end of period before its start', function (): void {
    $this->postJson('/api/v1/expense-management/reports', ['period_start' => '2028-02-10', 'period_end' => '2028-02-01'])->assertStatus(422)->assertJsonPath('code', 'period_invalid');
});

it('splits the VAT out of a gross amount and recovers it only with a P.IVA on a traceable payment (IT)', function (): void {
    $report = exmCreateReport($this);

    $withTaxId = exmAddLine($this, $report, 'lodging', ['original_amount' => '110.00', 'vat_rate' => '10', 'merchant_tax_id' => 'IT01234567890'])->assertCreated();
    expect($withTaxId->json('data.vat_amount'))->toBe('10.00')
        ->and($withTaxId->json('data.vat_recoverable_amount'))->toBe('10.00');

    $withoutTaxId = exmAddLine($this, $report, 'lodging', ['original_amount' => '110.00', 'vat_rate' => '10', 'merchant_name' => 'Hotel Beta'])->assertCreated();
    expect($withoutTaxId->json('data.vat_recoverable_amount'))->toBe('0.00')
        ->and(collect($withoutTaxId->json('data.policy_violations'))->pluck('code')->all())->toContain('missing_merchant_tax_id');
});

it('flags a cash meal, lodging or taxi as non-traceable and taxable, and a card or traceable payment as fine (IT)', function (): void {
    $report = exmCreateReport($this);

    $cash = exmAddLine($this, $report, 'meals', ['is_traceable_payment' => false])->assertCreated();
    expect($cash->json('data.is_traceable_payment'))->toBeFalse()
        ->and($cash->json('data.is_taxable_benefit'))->toBeTrue()
        ->and(collect($cash->json('data.policy_violations'))->pluck('code')->all())->toContain('non_traceable_payment');

    $card = exmAddLine($this, $report, 'meals', ['merchant_name' => 'Osteria Gamma'])->assertCreated();
    expect($card->json('data.is_taxable_benefit'))->toBeFalse();

    $fuelCash = exmAddLine($this, $report, 'supplies', ['is_traceable_payment' => false, 'merchant_name' => 'Cartoleria'])->assertCreated();
    expect($fuelCash->json('data.is_taxable_benefit'))->toBeFalse();

    $totals = $this->getJson("/api/v1/expense-management/reports/{$report}")->json('data');
    expect($totals['total_amount'])->toBe('120.00')->and($totals['taxable_amount'])->toBe('40.00');
});

it('converts a foreign currency receipt with the entered rate and refuses a conversion without a rate', function (): void {
    $report = exmCreateReport($this);

    $converted = exmAddLine($this, $report, 'supplies', ['original_amount' => '100.00', 'original_currency' => 'USD', 'exchange_rate' => '0.9', 'merchant_name' => 'Staples NY'])->assertCreated();
    expect($converted->json('data.amount_in_base_currency'))->toBe('90.00');

    exmAddLine($this, $report, 'supplies', ['original_currency' => 'JPY', 'merchant_name' => 'Tokyo Shop'])->assertStatus(422)->assertJsonPath('code', 'currency_rate_missing');
});

it('warns on exceeding the item cap and the daily cap of the policy, and on a missing receipt above the threshold', function (): void {
    $report = exmCreateReport($this);

    $line = exmAddLine($this, $report, 'meals', ['original_amount' => '75.00'])->assertCreated();
    $codes = collect($line->json('data.policy_violations'))->pluck('code')->all();
    expect($codes)->toContain('exceeds_item_cap')->and($codes)->toContain('receipt_required')->and($codes)->not->toContain('exceeds_daily_cap');

    $second = exmAddLine($this, $report, 'meals', ['original_amount' => '30.00', 'merchant_name' => 'Pizzeria Delta'])->assertCreated();
    expect(collect($second->json('data.policy_violations'))->pluck('code')->all())->toContain('exceeds_daily_cap');
});

it('detects a duplicate by merchant, date and amount, and by the SHA-256 of the receipt', function (): void {
    Storage::fake('local');
    $report = exmCreateReport($this);

    exmAddLine($this, $report, 'meals', ['merchant_name' => 'Trattoria  Roma!'])->assertCreated();
    $again = exmAddLine($this, $report, 'meals', ['merchant_name' => 'trattoria roma'])->assertCreated();
    expect(collect($again->json('data.policy_violations'))->pluck('code')->all())->toContain('duplicate_merchant_date_amount');

    $file = UploadedFile::fake()->createWithContent('scontrino.txt', "Bar Centrale\n12/03/2028\nTOTALE EUR 8,40\nIVA 10% 0,76\n");
    $scan = $this->postJson('/api/v1/expense-management/receipts/scan', ['file' => $file])->assertOk();
    $receiptId = $scan->json('data.receipt_id');
    expect($scan->json('data.ocr.total_amount'))->toBe('8.40')->and($scan->json('data.sha256'))->toHaveLength(64);

    $first = exmAddLine($this, $report, 'meals', ['merchant_name' => 'Bar Centrale', 'original_amount' => '8.40', 'receipt_id' => $receiptId])->assertCreated();
    expect($first->json('data.has_receipt'))->toBeTrue();

    $second = exmAddLine($this, $report, 'meals', ['merchant_name' => 'Altro Bar', 'original_amount' => '9.00', 'expense_date' => now()->subDay()->toDateString(), 'receipt_id' => $receiptId])->assertCreated();
    $violation = collect($second->json('data.policy_violations'))->firstWhere('code', 'duplicate_receipt_hash');
    expect($violation['severity'])->toBe('blocking');

    $rescan = $this->postJson('/api/v1/expense-management/receipts/scan', ['file' => UploadedFile::fake()->createWithContent('copy.txt', "Bar Centrale\n12/03/2028\nTOTALE EUR 8,40\nIVA 10% 0,76\n")])->assertOk();
    expect($rescan->json('data.receipt_id'))->toBe($receiptId)
        ->and(collect($rescan->json('data.duplicate_warnings'))->pluck('code')->all())->toContain('duplicate_receipt_hash');
});

it('does not let a line of a closed report change and recalculates totals when a line is removed', function (): void {
    $report = exmCreateReport($this);
    $line = exmAddLine($this, $report, 'supplies', ['original_amount' => '20.00', 'merchant_name' => 'Cancelleria'])->assertCreated()->json('data.id');

    $this->deleteJson("/api/v1/expense-management/reports/{$report}/lines/{$line}")->assertOk()->assertJsonPath('meta.report_totals.total_amount', '0.00');

    ExpenseReport::query()->whereKey($report)->update(['status' => 'submitted']);
    exmAddLine($this, $report, 'supplies')->assertStatus(409)->assertJsonPath('code', 'report_not_editable');
    expect(ExpenseLine::query()->where('expense_report_id', $report)->count())->toBe(0);
});

it('isolates reports between companies', function (): void {
    $report = exmCreateReport($this);
    $other = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = treasuryActor($this, 'owner', $other);

    $this->getJson("/api/v1/expense-management/reports/{$report}")->assertNotFound();
    expect($stranger)->not->toBeNull();
});
