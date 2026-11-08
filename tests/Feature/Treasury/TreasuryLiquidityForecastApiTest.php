<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Treasury\Models\ForecastScenario;
use Modules\Treasury\Models\RecurringCashItem;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001'], ['current_balance' => '5000.00']);
    treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null, ['current_balance' => '999999.00']);

    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente SPA', 'email' => 'c@example.com', 'type' => 'customer', 'country' => 'IT']);
    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore SPA', 'email' => 's@example.com', 'type' => 'supplier', 'country' => 'IT']);

    // Receivable of 2,000 due in 10 days, an overdue one of 500, a paid one and a proforma that never count.
    $invoice = static fn (string $number, string $gross, string $due, array $overrides = []): Invoice => Invoice::withoutGlobalScopes()->create(array_merge([
        'tenant_id' => test()->tenant->id, 'contact_id' => test()->customer->id, 'invoice_number' => $number, 'date' => now()->subDays(20)->toDateString(), 'due_date' => $due,
        'currency' => 'EUR', 'total_net' => $gross, 'total_tax' => '0', 'total_gross' => $gross, 'invoice_type' => 'standard', 'payment_status' => 'unpaid',
    ], $overrides));
    $invoice('INV-1', '2000.00', now()->addDays(10)->toDateString());
    $invoice('INV-OVERDUE', '500.00', now()->subDays(5)->toDateString());
    $invoice('INV-PAID', '700.00', now()->addDays(3)->toDateString(), ['payment_status' => 'paid', 'total_paid' => '700.00']);
    $invoice('PRO-1', '9000.00', now()->addDays(3)->toDateString(), ['invoice_type' => 'proforma']);

    // Payable of 6,000 due in 20 days.
    treasurySupplierInvoice($this->tenant, $this->supplier, 'SUP-1', '6000.00', ['due_date' => now()->addDays(20)->toDateString()]);
});

test('the liquidity forecast projects receivables, payables and recurring items for each scenario', function (): void {
    $this->postJson('/api/v1/treasury/recurring-items', ['name' => 'Affitto', 'category' => 'rent', 'direction' => 'outflow', 'amount' => '800.00', 'currency' => 'EUR', 'frequency' => 'monthly', 'next_date' => now()->addDays(15)->toDateString()])
        ->assertCreated()->assertJsonPath('data.amount', '800.00');
    $this->postJson('/api/v1/treasury/forecast-scenarios', ['code' => 'PESSIMISTIC', 'name' => 'Pessimistic', 'collection_delay_days' => 30, 'disbursement_acceleration_days' => 5, 'sales_growth_factor' => '0.5'])
        ->assertCreated()->assertJsonPath('data.code', 'PESSIMISTIC');

    $forecast = $this->getJson('/api/v1/treasury/liquidity/forecast?horizon_days=30&minimum_balance=1000&scenarios[]=BASELINE&scenarios[]=PESSIMISTIC')->assertOk()->json('data');

    expect($forecast['opening_cash'])->toBe('5000.00')->and($forecast['currency'])->toBe('EUR')->and($forecast['scenarios'])->toHaveCount(2);

    [$baseline, $pessimistic] = $forecast['scenarios'];
    expect($baseline)->toMatchArray(['scenario' => 'BASELINE', 'total_inflows' => '2500.00', 'total_outflows' => '6800.00', 'closing_balance' => '700.00', 'first_day_below_minimum' => now()->addDays(20)->toDateString()])
        ->and($baseline['net_by_source'])->toBe(['receivable' => '2500.00', 'payable' => '-6000.00', 'recurring' => '-800.00'])
        ->and($baseline['days'][0])->toBe(['date' => now()->toDateString(), 'inflows' => '500.00', 'outflows' => '0.00', 'balance' => '5500.00'])
        ->and($baseline['lowest_balance'])->toBe(['date' => now()->addDays(20)->toDateString(), 'balance' => '700.00']);

    // Collections 30 days later leave only the overdue invoice, halved, inside the horizon; payments come 5 days earlier.
    expect($pessimistic)->toMatchArray(['scenario' => 'PESSIMISTIC', 'total_inflows' => '250.00', 'total_outflows' => '6800.00', 'closing_balance' => '-1550.00'])
        ->and($pessimistic['assumptions'])->toBe(['collection_delay_days' => 30, 'disbursement_acceleration_days' => 5, 'sales_growth_factor' => '0.5000'])
        ->and(collect($pessimistic['days'])->firstWhere('outflows', '6000.00')['date'])->toBe(now()->addDays(15)->toDateString());
});

test('scenarios, recurring items and the forecast are validated and isolated', function (): void {
    $this->getJson('/api/v1/treasury/liquidity/forecast?horizon_days=0&currency=EURO&minimum_balance=abc')->assertUnprocessable()->assertJsonValidationErrors(['horizon_days', 'currency', 'minimum_balance']);
    $this->postJson('/api/v1/treasury/forecast-scenarios', ['code' => 'lower case', 'sales_growth_factor' => 9])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'sales_growth_factor']);
    $this->postJson('/api/v1/treasury/recurring-items', ['direction' => 'sideways', 'frequency' => 'daily', 'amount' => 0, 'next_date' => '2026-02-30'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'direction', 'frequency', 'amount', 'currency', 'next_date']);

    treasuryInTenant($this->otherTenant, fn () => ForecastScenario::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'code' => 'THEIRS', 'name' => 'Theirs', 'collection_delay_days' => 365]));
    $foreignItem = treasuryInTenant($this->otherTenant, fn () => RecurringCashItem::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'name' => 'Their rent', 'direction' => 'outflow', 'amount' => '100', 'currency' => 'EUR', 'frequency' => 'monthly', 'next_date' => now()->toDateString(), 'is_active' => true]));

    $this->getJson('/api/v1/treasury/forecast-scenarios')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/treasury/recurring-items')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/treasury/recurring-items/{$foreignItem->id}")->assertNotFound();

    // A scenario of another company is unknown here: it falls back to the baseline assumptions.
    $theirs = $this->getJson('/api/v1/treasury/liquidity/forecast?horizon_days=30&scenarios[]=THEIRS')->assertOk()->json('data.scenarios.0');
    expect($theirs['assumptions']['collection_delay_days'])->toBe(0)->and($theirs['total_outflows'])->toBe('6000.00');

    $own = $this->postJson('/api/v1/treasury/recurring-items', ['name' => 'Leasing', 'direction' => 'outflow', 'amount' => '100.00', 'currency' => 'EUR', 'frequency' => 'weekly', 'next_date' => now()->toDateString(), 'end_date' => now()->addDays(14)->toDateString()])->assertCreated()->json('data.id');
    expect($this->getJson('/api/v1/treasury/liquidity/forecast?horizon_days=30')->json('data.scenarios.0.net_by_source.recurring'))->toBe('-300.00');
    $this->deleteJson("/api/v1/treasury/recurring-items/{$own}")->assertOk();
});

test('treasury KPIs report DSO, DPO and the cash conversion cycle from the real invoices', function (): void {
    $kpis = $this->getJson('/api/v1/treasury/kpis?period_days=30')->assertOk()->json('data');

    // Sales of the last 30 days: 2,000 + 500 + 700 (paid); open receivables 2,500; purchases 6,000 all open.
    expect($kpis)->toMatchArray([
        'currency' => 'EUR', 'sales' => '3200.00', 'purchases' => '6000.00', 'open_receivables' => '2500.00', 'overdue_receivables' => '500.00',
        'open_payables' => '6000.00', 'overdue_payables' => '0.00', 'cash' => '5000.00', 'net_liquidity' => '1500.00', 'quick_ratio' => '1.250',
        'dso_days' => '23.4', 'dpo_days' => '30.0', 'dio_days' => null, 'cash_conversion_cycle_days' => '-6.6', 'includes_inventory' => false,
    ]);

    // Without sales in the period the DSO is not estimated.
    expect($this->getJson('/api/v1/treasury/kpis?period_days=7&as_of_date='.now()->subYear()->toDateString())->json('data'))->toMatchArray(['dso_days' => null, 'dpo_days' => null, 'cash_conversion_cycle_days' => null]);

    $this->getJson('/api/v1/treasury/kpis?period_days=1&as_of_date=yesterday')->assertUnprocessable()->assertJsonValidationErrors(['period_days', 'as_of_date']);

    // The stored working capital metric uses the same real figures.
    $metric = $this->getJson('/api/v1/treasury/liquidity/working-capital')->assertOk()->json();
    expect((float) ($metric['accounts_receivable_total'] ?? $metric['data']['accounts_receivable_total']))->toBe(2500.0);
});

test('read-only roles read the forecast and KPIs but cannot change scenarios or recurring items', function (): void {
    treasuryActor($this, 'accountant_readonly');

    $this->getJson('/api/v1/treasury/liquidity/forecast')->assertOk();
    $this->getJson('/api/v1/treasury/kpis')->assertOk();
    $this->getJson('/api/v1/treasury/positions/cash')->assertOk();
    $this->postJson('/api/v1/treasury/forecast-scenarios', ['code' => 'RO', 'name' => 'RO'])->assertForbidden();
    $this->postJson('/api/v1/treasury/recurring-items', ['name' => 'RO', 'direction' => 'outflow', 'amount' => '1', 'currency' => 'EUR', 'frequency' => 'monthly', 'next_date' => now()->toDateString()])->assertForbidden();

    treasuryActor($this, 'member');
    $this->getJson('/api/v1/treasury/kpis')->assertForbidden();
});
