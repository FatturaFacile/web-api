<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use Database\Factories\BankStatementFactory;
use Database\Seeders\AgentCommissionsReferenceSeeder;
use Illuminate\Support\Str;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Models\FiscalParameter;
use Modules\AgentCommissions\Services\CommissionAccrualService;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Treasury\Models\BankStatementLine;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the agent commission tests (Mod 041): a company with the seeded statutory fiscal parameters, an accountant
 * signed in, a customer, a sales representative and invoices, payments and bank lines the ledger reads.
 */
function agcSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Provvigioni Srl', 'legal_name' => 'Provvigioni Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    (new AgentCommissionsReferenceSeeder)->run();
    $test->accountant = treasuryActor($test, 'accountant');
    $test->customer = agcCustomer($test, 'Cliente Uno Srl');
    $test->rep = agcRepresentative($test, 'Mario Agente', 'mario.agente@example.com');
}

function agcCustomer(object $test, string $name, string $country = 'IT'): Contact
{
    return treasuryInTenant($test->tenant, fn (): Contact => Contact::create([
        'tenant_id' => $test->tenant->id, 'company_name' => $name, 'email' => Str::slug($name).'@example.com', 'type' => 'customer', 'country' => $country,
    ]));
}

function agcRepresentative(object $test, string $name, ?string $email, string $rate = '3'): SalesRepresentative
{
    return treasuryInTenant($test->tenant, fn (): SalesRepresentative => SalesRepresentative::create([
        'tenant_id' => $test->tenant->id, 'rep_code' => 'REP-'.strtoupper(Str::random(5)), 'name' => $name, 'email' => $email, 'default_commission_rate' => $rate,
    ]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function agcAgent(object $test, ?SalesRepresentative $rep = null, array $attributes = []): AgentProfile
{
    return treasuryInTenant($test->tenant, fn (): AgentProfile => AgentProfile::create(array_merge([
        'tenant_id' => $test->tenant->id,
        'sales_representative_id' => ($rep ?? $test->rep)->id,
        'agent_type' => 'single_mandate',
        'fiscal_regime' => 'enasarco',
        'accrual_basis' => 'invoiced',
        'default_rate' => '0',
    ], $attributes)));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function agcRule(object $test, string $name, array $attributes = []): CommissionRule
{
    return treasuryInTenant($test->tenant, fn (): CommissionRule => CommissionRule::create(array_merge([
        'tenant_id' => $test->tenant->id, 'name' => $name, 'rate_type' => 'percentage', 'rate' => '1', 'priority' => 100, 'is_active' => true,
    ], $attributes)));
}

function agcCategory(object $test, string $name): ProductCategory
{
    return treasuryInTenant($test->tenant, fn (): ProductCategory => ProductCategory::create(['tenant_id' => $test->tenant->id, 'name' => $name]));
}

function agcProduct(object $test, string $sku, ?ProductCategory $category = null): Product
{
    return treasuryInTenant($test->tenant, fn (): Product => Product::create([
        'tenant_id' => $test->tenant->id, 'sku' => $sku, 'name' => 'Prodotto '.$sku, 'category_id' => $category?->id, 'sale_price' => '100', 'unit_measure' => 'PZ', 'tax_rate' => '22',
    ]));
}

/**
 * An issued sales invoice of the test agent. The lines are created while the invoice is a draft (issued invoices are
 * immutable), then the invoice is posted: that save is what the ledger observes.
 *
 * @param  list<array{net: string, product?: Product|null}>  $lines
 * @param  array<string, mixed>  $overrides
 */
function agcInvoice(object $test, array $lines, string $date = '2027-03-10', array $overrides = []): SalesInvoice
{
    return treasuryInTenant($test->tenant, function () use ($test, $lines, $date, $overrides): SalesInvoice {
        $net = array_sum(array_map(static fn (array $line): float => (float) $line['net'], $lines));
        $invoice = SalesInvoice::factory()->create(array_merge([
            'tenant_id' => $test->tenant->id,
            'contact_id' => $test->customer->id,
            'invoice_number' => 'FT-'.strtoupper(Str::random(6)),
            'status' => 'draft',
            'date' => $date,
            'sales_rep_id' => $test->rep->id,
            'subtotal' => $net,
            'discount_amount' => 0,
            'total_net' => $net,
            'total_tax' => round($net * 0.22, 2),
            'total_gross' => round($net * 1.22, 2),
            'receivable_amount' => round($net * 1.22, 2),
        ], $overrides));

        foreach ($lines as $line) {
            SalesInvoiceItem::create([
                'tenant_id' => $test->tenant->id, 'invoice_id' => $invoice->id, 'product_id' => ($line['product'] ?? null)?->id,
                'description' => 'Riga', 'quantity' => 1, 'unit_price' => $line['net'], 'tax_rate' => 22, 'line_total' => $line['net'],
            ]);
        }

        $invoice->update(['status' => 'posted']);

        return $invoice->refresh();
    });
}

/**
 * Brings the ledger in line with an invoice (the observers already did it; this is the explicit entry point).
 *
 * @return list<CommissionEntry>
 */
function agcSync(SalesInvoice $invoice): array
{
    app(CommissionAccrualService::class)->syncInvoice((string) $invoice->id);

    return CommissionEntry::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()->all();
}

function agcBankLine(object $test, bool $reconciled, string $amount = '610.00'): BankStatementLine
{
    return treasuryInTenant($test->tenant, function () use ($test, $reconciled, $amount): BankStatementLine {
        $account = treasuryBank($test->tenant, 'IT60X0542811101000000'.random_int(100000, 999999), null);
        $statement = BankStatementFactory::new()->create(['tenant_id' => $test->tenant->id, 'company_id' => $test->tenant->id, 'bank_account_id' => $account->id]);

        return BankStatementLine::create([
            'tenant_id' => $test->tenant->id, 'bank_statement_id' => $statement->id, 'line_number' => 1, 'booking_date' => '2027-03-20', 'value_date' => '2027-03-20',
            'amount' => $amount, 'direction' => 'credit', 'description' => 'Bonifico cliente', 'reconciled' => $reconciled,
        ]);
    });
}

function agcPayment(object $test, SalesInvoice $invoice, string $amount, ?BankStatementLine $line = null): Payment
{
    return treasuryInTenant($test->tenant, fn (): Payment => Payment::create([
        'invoice_id' => $invoice->id, 'amount' => $amount, 'paid_at' => '2027-03-20', 'type' => 'payment', 'bank_statement_line_id' => $line?->id,
    ]));
}

/**
 * A tenant override of a fiscal parameter.
 */
function agcOverride(object $test, string $code, string $value, ?string $mandate = null): FiscalParameter
{
    return treasuryInTenant($test->tenant, fn (): FiscalParameter => FiscalParameter::create([
        'tenant_id' => $test->tenant->id, 'code' => $code, 'mandate_type' => $mandate, 'year' => null, 'value' => $value,
    ]));
}
