<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\ProductChargeRate;
use Modules\Inventory\Services\ProductChargeCalculator;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoicingService;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->account = ChartOfAccount::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'account_code' => '4800', 'name' => 'Charges', 'account_class' => 'revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'currency' => 'CHF', 'is_active' => true]);
    $this->product = Product::create(['sku' => 'P-'.Str::random(6), 'name' => 'Frigo', 'sale_price' => 100]);
    $this->contact = Contact::create(['company_name' => 'Cliente']);

    // Franc invoices of a company without an accounting currency (euro) take the rate of the exchange-rate service.
    ExchangeRate::createGlobal(['source' => 'EUR', 'target' => 'CHF', 'rate' => '1.000000', 'effective_date' => '2026-01-01']);
});

/**
 * @param  array<string, mixed>  $charge
 */
function chargeOnProduct(array $charge = [], string $rate = '2.50', string $quantityPerUnit = '4', string $from = '2026-01-01', ?string $to = null): string
{
    $id = test()->postJson('/api/v1/invoicing/product-charges', $charge + ['code' => 'TRA', 'name' => 'Tassa riciclaggio', 'calculation_basis' => 'per_kg', 'revenue_account_id' => test()->account->id])->assertCreated()->json('id');
    test()->postJson("/api/v1/invoicing/product-charges/{$id}/rates", ['rate' => $rate, 'currency' => 'CHF', 'valid_from' => $from, 'valid_to' => $to])->assertCreated();
    test()->postJson("/api/v1/invoicing/product-charges/{$id}/products", ['product_id' => test()->product->id, 'quantity_per_unit' => $quantityPerUnit])->assertCreated();

    return $id;
}

function chargedInvoice(array $overrides = []): SalesInvoice
{
    return app(InvoicingService::class)->createInvoice($overrides + [
        'tenant_id' => test()->tenant->id, 'contact_id' => test()->contact->id, 'currency' => 'CHF', 'date' => '2026-06-01',
    ], [['product_id' => test()->product->id, 'description' => 'Frigo', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 8.1]]);
}

test('an invoice carries the charge on its own line with its own account', function (): void {
    $chargeId = chargeOnProduct();

    $invoice = chargedInvoice();
    $line = $invoice->items->firstWhere('product_charge_type_id', $chargeId);

    expect($invoice->items)->toHaveCount(2)
        ->and($line->description)->toBe('Tassa riciclaggio')
        ->and((string) $line->line_total)->toBe('30.00')
        ->and((float) $line->tax_rate)->toBe(8.1)
        ->and($line->revenue_account_id)->toBe($this->account->id)
        ->and((string) $invoice->total_net)->toBe('330.00');
});

test('the charge reaches its ledger account when the invoice is posted', function (): void {
    chargeOnProduct();
    $year = FiscalYear::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'year_code' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    FiscalPeriod::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'fiscal_year_id' => $year->id, 'period_number' => 6, 'period_name' => 'June 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open']);

    $invoice = app(InvoicingService::class)->postInvoice(chargedInvoice(), (string) Str::uuid7());

    expect($invoice->status)->toBe('posted')
        ->and((float) JournalLine::query()->where('account_id', $this->account->id)->sum('credit_amount'))->toBe(30.0);
});

test('the rate follows the date of the document', function (): void {
    $id = chargeOnProduct(rate: '1.00', to: '2026-06-30');
    $this->postJson("/api/v1/invoicing/product-charges/{$id}/rates", ['rate' => '2.00', 'currency' => 'CHF', 'valid_from' => '2026-07-01'])->assertCreated();
    $amountOn = fn (string $date): float => app(ProductChargeCalculator::class)->linesFor([['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 8.1]], $date, 'CHF', $this->tenant->id)[0]['unit_price'];

    expect($amountOn('2026-06-30'))->toBe(4.0)->and($amountOn('2026-07-01'))->toBe(8.0);
});

test('a percentage works on the discounted line', function (): void {
    chargeOnProduct(['calculation_basis' => 'percent_of_net'], '2');

    $lines = app(ProductChargeCalculator::class)->linesFor([['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 8.1, 'discount_percentage' => 10]], '2026-06-01', 'CHF', $this->tenant->id);

    expect($lines[0]['unit_price'])->toBe(1.8);
});

test('lines with different vat rates stay apart and no rate stops the document', function (): void {
    chargeOnProduct();
    $calculator = app(ProductChargeCalculator::class);
    $line = ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10];

    expect($calculator->linesFor([$line + ['tax_rate' => 8.1], $line + ['tax_rate' => 2.6]], '2026-06-01', 'CHF', $this->tenant->id))->toHaveCount(2)
        ->and(fn () => $calculator->linesFor([$line + ['tax_rate' => 8.1]], '2025-01-01', 'CHF', $this->tenant->id))->toThrow(StockRuleViolation::class)
        ->and(fn () => $calculator->linesFor([$line + ['tax_rate' => 8.1]], '2026-06-01', 'EUR', $this->tenant->id))->toThrow(StockRuleViolation::class);
});

test('lines that already carry a charge are not charged twice', function (): void {
    $chargeId = chargeOnProduct();
    $lines = [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 8.1], ['product_charge_type_id' => $chargeId, 'quantity' => 1, 'unit_price' => 4, 'tax_rate' => 8.1]];

    expect(app(ProductChargeCalculator::class)->linesFor($lines, '2026-06-01', 'CHF', $this->tenant->id))->toBe([]);
});

test('overlapping rate periods are refused, and the preview shows the line', function (): void {
    $id = chargeOnProduct(to: '2026-12-31');

    $this->postJson("/api/v1/invoicing/product-charges/{$id}/rates", ['rate' => '1', 'currency' => 'CHF', 'valid_from' => '2026-06-01'])->assertStatus(422);
    $this->postJson('/api/v1/invoicing/product-charges/preview', ['date' => '2026-06-01', 'currency' => 'CHF', 'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 8.1]]])
        ->assertOk()
        ->assertJsonPath('lines.0.unit_price', 20);
});

test('no figures are seeded', function (): void {
    expect(ProductChargeRate::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('charges stay within the tenant and need invoicing write access', function (): void {
    $id = chargeOnProduct();

    logisticsUser($this->tenant, 'warehouse');
    $this->getJson('/api/v1/invoicing/product-charges')->assertOk();
    $this->postJson('/api/v1/invoicing/product-charges', ['code' => 'X'])->assertForbidden();

    $other = logisticsTenant('Beta');
    logisticsUser($other);
    $this->getJson('/api/v1/invoicing/product-charges')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/invoicing/product-charges/{$id}/rates", ['rate' => '1', 'currency' => 'CHF', 'valid_from' => '2030-01-01'])->assertNotFound();
    $this->postJson('/api/v1/invoicing/product-charges', ['code' => 'Y', 'name' => 'Y', 'calculation_basis' => 'per_unit', 'revenue_account_id' => $this->account->id])->assertStatus(422);
});
