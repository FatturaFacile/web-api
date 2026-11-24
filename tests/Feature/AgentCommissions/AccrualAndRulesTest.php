<?php

declare(strict_types=1);

use App\Models\ContactAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\AgentCommissions\Models\CommissionRuleTier;
use Modules\AgentCommissions\Models\Zone;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;

require_once __DIR__.'/AgentCommissionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    agcSetUp($this);
    $this->agent = agcAgent($this);
});

/**
 * @param  list<CommissionEntry>  $entries
 */
function agcTotal(array $entries): string
{
    return number_format(array_sum(array_map(static fn (CommissionEntry $entry): float => (float) $entry->commission_amount, $entries)), 2, '.', '');
}

it('accrues the commission of an issued invoice when it is posted, from the observers, once', function (): void {
    agcRule($this, 'General', ['rate' => '2']);
    $invoice = agcInvoice($this, [['net' => '1000.00']]);

    $entries = CommissionEntry::query()->where('invoice_id', $invoice->id)->get();
    expect($entries)->toHaveCount(1)
        ->and((string) $entries->first()->commission_amount)->toBe('20.00')
        ->and($entries->first()->status)->toBe('eligible')
        ->and((string) $entries->first()->eligible_amount)->toBe('20.00');

    agcSync($invoice);
    agcSync($invoice);

    expect(CommissionEntry::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and($entries->first()->movements()->count())->toBe(1);
});

it('does not accrue a draft with the placeholder number or an invoice of a sales representative without an active agent profile', function (): void {
    agcRule($this, 'General', ['rate' => '2']);
    $draft = treasuryInTenant($this->tenant, function (): SalesInvoice {
        $draft = SalesInvoice::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'invoice_number' => 'DRAFT-0001', 'status' => 'draft', 'date' => '2027-03-10', 'sales_rep_id' => $this->rep->id, 'total_gross' => '1220.00']);
        SalesInvoiceItem::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $draft->id, 'description' => 'Riga', 'quantity' => 1, 'unit_price' => '1000', 'tax_rate' => 22, 'line_total' => '1000.00']);

        return $draft;
    });
    agcSync($draft);
    expect(CommissionEntry::query()->where('invoice_id', $draft->id)->count())->toBe(0);

    $other = agcRepresentative($this, 'Senza profilo', null);
    $orphan = agcInvoice($this, [['net' => '800.00']], '2027-03-11', ['sales_rep_id' => $other->id]);
    expect(CommissionEntry::query()->where('invoice_id', $orphan->id)->count())->toBe(0);
});

it('picks the rule by priority, then specificity (customer, product line, zone), then the rule of the agent', function (): void {
    $category = agcCategory($this, 'Linea A');
    $product = agcProduct($this, 'SKU-A', $category);
    $plain = agcProduct($this, 'SKU-B');
    agcRule($this, 'General', ['rate' => '2']);
    agcRule($this, 'Line A', ['rate' => '5', 'product_category_id' => $category->id]);

    $first = agcInvoice($this, [['net' => '1000.00', 'product' => $product], ['net' => '500.00', 'product' => $plain]]);
    expect(agcTotal(agcSync($first)))->toBe('60.00'); // 1000 x 5% + 500 x 2%

    agcRule($this, 'Customer', ['rate' => '7', 'customer_id' => $this->customer->id]);
    $second = agcInvoice($this, [['net' => '1000.00', 'product' => $product], ['net' => '500.00', 'product' => $plain]], '2027-03-11');
    expect(agcTotal(agcSync($second)))->toBe('105.00'); // the customer rule is more specific than the product line

    agcRule($this, 'Override', ['rate' => '1', 'priority' => 10]);
    $third = agcInvoice($this, [['net' => '1000.00', 'product' => $product]], '2027-03-12');
    expect(agcTotal(agcSync($third)))->toBe('10.00'); // priority 10 beats every specificity

    agcRule($this, 'Agent tie', ['rate' => '9', 'priority' => 10, 'agent_profile_id' => $this->agent->id]);
    $fourth = agcInvoice($this, [['net' => '1000.00']], '2027-03-13');
    expect(agcTotal(agcSync($fourth)))->toBe('90.00'); // same priority and specificity: the agent's own rule wins
});

it('matches a rule by zone through the country and province of the customer', function (): void {
    treasuryInTenant($this->tenant, function (): void {
        $zone = Zone::create(['tenant_id' => $this->tenant->id, 'code' => 'NORD', 'name' => 'Nord', 'country' => 'IT', 'regions' => ['MI', 'TO']]);
        ContactAddress::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'street' => 'Via Roma', 'postal_code' => '20100', 'city' => 'Milano', 'state_canton' => 'MI', 'country' => 'IT', 'type' => 'billing', 'is_default' => true]);
        agcRule($this, 'Zone Nord', ['rate' => '6', 'zone_id' => $zone->id]);
    });
    agcRule($this, 'General', ['rate' => '2']);

    $north = agcInvoice($this, [['net' => '1000.00']]);
    expect(agcTotal(agcSync($north)))->toBe('60.00');

    $southCustomer = agcCustomer($this, 'Cliente Sud', 'IT');
    treasuryInTenant($this->tenant, fn () => ContactAddress::create(['tenant_id' => $this->tenant->id, 'contact_id' => $southCustomer->id, 'street' => 'Via Napoli', 'postal_code' => '80100', 'city' => 'Napoli', 'state_canton' => 'NA', 'country' => 'IT', 'type' => 'billing', 'is_default' => true]));
    $south = agcInvoice($this, [['net' => '1000.00']], '2027-03-11', ['contact_id' => $southCustomer->id]);
    expect(agcTotal(agcSync($south)))->toBe('20.00');
});

it('falls back to the default rate of the profile, then to the default rate of the sales representative', function (): void {
    $this->agent->update(['default_rate' => '4']);
    $invoice = agcInvoice($this, [['net' => '1000.00']]);
    expect(agcTotal(agcSync($invoice)))->toBe('40.00');

    $this->agent->update(['default_rate' => null]);
    $next = agcInvoice($this, [['net' => '1000.00']], '2027-03-11');
    expect(agcTotal(agcSync($next)))->toBe('30.00'); // the representative's 3%
});

it('spreads a fixed commission over the lines of the invoice in proportion to their base', function (): void {
    agcRule($this, 'Fixed fee', ['rate_type' => 'fixed', 'fixed_amount' => '120', 'rate' => '0']);
    $invoice = agcInvoice($this, [['net' => '1000.00'], ['net' => '500.00']]);
    $entries = agcSync($invoice);

    expect($entries)->toHaveCount(2)->and(agcTotal($entries))->toBe('120.00');
});

it('applies progressive tiers over the yearly turnover of the rule', function (): void {
    $rule = agcRule($this, 'Tiered', ['rate' => '0']);
    treasuryInTenant($this->tenant, function () use ($rule): void {
        CommissionRuleTier::create(['tenant_id' => $this->tenant->id, 'rule_id' => $rule->id, 'threshold_from' => '0', 'threshold_to' => '1000', 'rate' => '2']);
        CommissionRuleTier::create(['tenant_id' => $this->tenant->id, 'rule_id' => $rule->id, 'threshold_from' => '1000', 'threshold_to' => null, 'rate' => '3']);
    });

    $first = agcInvoice($this, [['net' => '800.00']]);
    expect(agcTotal(agcSync($first)))->toBe('16.00');

    $second = agcInvoice($this, [['net' => '400.00']], '2027-04-10');
    expect(agcTotal(agcSync($second)))->toBe('10.00'); // 200 x 2% + 200 x 3%
});

it('pays the parent agent of a sub-agent either an additional rate or a share of the sub-agent commission', function (): void {
    $parentRep = agcRepresentative($this, 'Capo Area', 'capo@example.com');
    $parent = agcAgent($this, $parentRep, ['agent_type' => 'direct']);
    $subRep = agcRepresentative($this, 'Sub Agente', 'sub@example.com');
    $sub = agcAgent($this, $subRep, ['agent_type' => 'sub_agent', 'parent_agent_profile_id' => $parent->id, 'parent_split_mode' => 'additional_rate', 'parent_split_value' => '1', 'default_rate' => '5']);

    $additional = agcInvoice($this, [['net' => '1000.00']], '2027-03-10', ['sales_rep_id' => $subRep->id]);
    $entries = collect(agcSync($additional));
    expect((string) $entries->firstWhere('role', 'primary')->commission_amount)->toBe('50.00')
        ->and((string) $entries->firstWhere('role', 'parent_override')->commission_amount)->toBe('10.00')
        ->and($entries->firstWhere('role', 'parent_override')->agent_profile_id)->toBe($parent->id);

    $sub->update(['parent_split_mode' => 'share_of_commission', 'parent_split_value' => '20']);
    $shared = agcInvoice($this, [['net' => '1000.00']], '2027-03-11', ['sales_rep_id' => $subRep->id]);
    $entries = collect(agcSync($shared));
    expect((string) $entries->firstWhere('role', 'primary')->commission_amount)->toBe('40.00')
        ->and((string) $entries->firstWhere('role', 'parent_override')->commission_amount)->toBe('10.00');
});

it('reduces the commission of an invoice by its credit notes instead of creating entries of their own', function (): void {
    agcRule($this, 'General', ['rate' => '10']);
    $invoice = agcInvoice($this, [['net' => '1000.00']]);
    expect((string) CommissionEntry::query()->where('invoice_id', $invoice->id)->first()->eligible_amount)->toBe('100.00');

    agcInvoice($this, [['net' => '500.00']], '2027-03-15', ['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id, 'sales_rep_id' => null]);

    $entry = CommissionEntry::query()->where('invoice_id', $invoice->id)->first();
    expect(CommissionEntry::query()->count())->toBe(1)
        ->and((string) $entry->credited_amount)->toBe('50.00')
        ->and((string) $entry->eligible_amount)->toBe('50.00')
        ->and($entry->movements()->pluck('kind')->all())->toBe(['accrual', 'credit_note']);
});

it('zeroes the commission of a cancelled invoice', function (): void {
    agcRule($this, 'General', ['rate' => '10']);
    $invoice = agcInvoice($this, [['net' => '1000.00']]);
    DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'cancelled']); // issued invoices are immutable through the model
    agcSync($invoice);

    $entry = CommissionEntry::query()->where('invoice_id', $invoice->id)->first();
    expect((string) $entry->eligible_amount)->toBe('0.00')->and($entry->status)->toBe('cancelled');
});
