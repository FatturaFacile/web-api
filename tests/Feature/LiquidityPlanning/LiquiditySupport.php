<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use Modules\LiquidityPlanning\Models\ForecastManualItem;
use Modules\Treasury\Models\BankAccount;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the liquidity planning tests (Mod 017). They reuse the treasury support: two companies with an open fiscal
 * calendar and an accountant signed in on the first one.
 */
function lpSetUp(object $test): void
{
    treasurySetUp($test);
    $test->customer = treasuryInTenant($test->tenant, fn (): Contact => Contact::create([
        'tenant_id' => $test->tenant->id, 'company_name' => 'Cliente Alfa SRL', 'email' => 'cliente@example.com', 'type' => 'customer', 'country' => 'IT',
    ]));
}

function lpBank(object $test, string $balance, string $iban = 'IT60X0542811101000000654321'): BankAccount
{
    return treasuryBank($test->tenant, $iban, $test->accounts['1001'], ['current_balance' => $balance, 'opening_balance' => $balance, 'available_balance' => $balance]);
}

/**
 * An open customer invoice falling due `$dueInDays` days from today.
 *
 * @param  array<string, mixed>  $overrides
 */
function lpInvoice(object $test, string $number, string $gross, int $dueInDays, array $overrides = []): Invoice
{
    return treasuryInTenant($test->tenant, fn (): Invoice => Invoice::create(array_merge([
        'tenant_id' => $test->tenant->id, 'contact_id' => $test->customer->id, 'invoice_number' => $number,
        'date' => now()->subDays(20)->toDateString(), 'due_date' => now()->addDays($dueInDays)->toDateString(), 'currency' => 'EUR',
        'total_net' => $gross, 'total_tax' => '0', 'total_gross' => $gross, 'payment_status' => 'unpaid', 'invoice_type' => 'standard',
    ], $overrides)));
}

/**
 * A manual forecast item of the company, projected on `$inDays` days from today.
 *
 * @param  array<string, mixed>  $overrides
 */
function lpManualItem(object $test, string $direction, string $category, string $amount, int $inDays, string $tier = 'certain', array $overrides = []): ForecastManualItem
{
    return treasuryInTenant($test->tenant, fn (): ForecastManualItem => ForecastManualItem::create(array_merge([
        'tenant_id' => $test->tenant->id, 'label' => $category.' '.$amount, 'tier' => $tier, 'category' => $category, 'direction' => $direction,
        'amount' => $amount, 'currency' => 'EUR', 'first_date' => now()->addDays($inDays)->toDateString(), 'frequency' => 'once', 'is_active' => true,
    ], $overrides)));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lpSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'jurisdiction' => 'IT', 'share_capital' => null, 'legal_minimum_capital' => null, 'minimum_liquidity' => '0', 'dscr_critical_threshold' => '1.00',
        'dscr_warning_threshold' => '1.20', 'max_pfn_ebitda' => '3.50', 'alert_cooldown_hours' => 24, 'notify_emails' => [], 'email_alerts_enabled' => true,
        'collection_behavior_days' => 0, 'payroll_pay_day' => 27,
    ], $overrides);
}

function apSupplierFor(object $test): Contact
{
    return treasuryInTenant($test->tenant, fn (): Contact => Contact::create([
        'tenant_id' => $test->tenant->id, 'company_name' => 'Fornitore Alfa SRL', 'email' => 'fornitore@example.com', 'type' => 'supplier', 'country' => 'IT',
    ]));
}

function lpManualItemFor(Tenant $tenant): void
{
    ForecastManualItem::create([
        'tenant_id' => $tenant->id, 'label' => 'Other tenant', 'tier' => 'certain', 'category' => 'receivable', 'direction' => 'inflow', 'amount' => '5000.00',
        'currency' => 'EUR', 'first_date' => now()->addDays(5)->toDateString(), 'frequency' => 'once', 'is_active' => true,
    ]);
}
