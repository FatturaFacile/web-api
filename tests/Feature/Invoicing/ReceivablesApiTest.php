<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PaymentTerm;
use App\Models\PaymentTermInstallment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Invoicing\Jobs\SendCustomerStatementJob;
use Modules\Invoicing\Models\ReceivableItem;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Services\InvoicingService;

require_once __DIR__.'/InvoicingBillingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    billingSetUp($this);
});

const AR = '/api/v1/invoicing/receivables';

/**
 * Issues an invoice of the first customer, with the given net amount, in `$installments` equal due dates.
 */
function receivableInvoice(string $net = '1000.00', int $installments = 1, ?Contact $customer = null): SalesInvoice
{
    $payload = [
        'contact_id' => ($customer ?? test()->customer)->id, 'invoice_type' => 'standard', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['description' => 'Licence', 'quantity' => 1, 'unit_price' => $net, 'tax_rate' => 0]],
    ];

    if ($installments > 1) {
        $term = billingInTenant(test()->tenant, function () use ($installments): PaymentTerm {
            $term = PaymentTerm::query()->create(['tenant_id' => test()->tenant->id, 'code' => 'R'.$installments, 'name' => $installments.' installments', 'days' => 30 * $installments, 'end_of_month' => false]);

            foreach (range(1, $installments) as $sequence) {
                PaymentTermInstallment::query()->create(['payment_term_id' => $term->id, 'sequence' => $sequence, 'percent' => number_format(100 / $installments, 2, '.', ''), 'days' => 30 * $sequence]);
            }

            return $term;
        });
        $payload['payment_term_id'] = $term->id;
    }

    $id = test()->postJson('/api/v1/sales/invoices', $payload)->assertCreated()->json('data.id');

    return SalesInvoice::withoutGlobalScopes()->findOrFail($id);
}

/**
 * @return list<array<string, mixed>>
 */
function receivableItems(?string $customerId = null, string $query = ''): array
{
    return test()->getJson(AR.'/items?customer_id='.($customerId ?? test()->customer->id).$query)->assertOk()->json('data');
}

test('an issued invoice opens one item per installment, together worth the amount due', function (): void {
    receivableInvoice('1000.00', 3);

    $items = receivableItems();
    $total = array_reduce($items, fn (string $sum, array $item): string => bcadd($sum, $item['open_amount'], 2), '0.00');

    expect($items)->toHaveCount(3)->and($total)->toBe('1000.00')
        ->and(collect($items)->pluck('status')->unique()->all())->toBe(['open']);
});

test('a partial allocation leaves the item partially paid and a full one pays it', function (): void {
    receivableInvoice('1000.00');
    $item = receivableItems()[0];

    $this->postJson(AR.'/allocations', ['allocations' => [['receivable_item_id' => $item['id'], 'type' => 'write_off', 'amount' => '250.00', 'value_date' => now()->toDateString()]]])
        ->assertCreated();

    $this->getJson(AR.'/items/'.$item['id'])->assertOk()
        ->assertJsonPath('data.status', 'partially_paid')
        ->assertJsonPath('data.open_amount', '750.00')
        ->assertJsonPath('data.value_date', now()->toDateString());

    $this->postJson(AR.'/allocations', ['allocations' => [['receivable_item_id' => $item['id'], 'type' => 'discount', 'amount' => '750.00', 'value_date' => now()->toDateString()]]])->assertCreated();

    $this->getJson(AR.'/items/'.$item['id'])->assertJsonPath('data.open_amount', '0.00')->assertJsonPath('data.status', 'paid');
});

test('an allocation over the open amount, or on a paid item, is refused', function (): void {
    receivableInvoice('100.00');
    $item = receivableItems()[0];
    $row = ['receivable_item_id' => $item['id'], 'type' => 'discount', 'value_date' => now()->toDateString()];

    $this->postJson(AR.'/allocations', ['allocations' => [$row + ['amount' => '100.01']]])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->postJson(AR.'/allocations', ['allocations' => [$row + ['amount' => '100.00']]])->assertCreated();
    $this->postJson(AR.'/allocations', ['allocations' => [$row + ['amount' => '1.00']]])->assertUnprocessable()->assertJsonValidationErrors('receivable_item_id');
});

test('a reversed allocation gives the amount back and stays on record', function (): void {
    receivableInvoice('500.00');
    $item = receivableItems()[0];
    $allocationId = $this->postJson(AR.'/allocations', ['allocations' => [['receivable_item_id' => $item['id'], 'type' => 'write_off', 'amount' => '500.00', 'value_date' => now()->toDateString()]]])
        ->assertCreated()->json('data.allocations.0.id');

    $this->getJson(AR.'/items/'.$item['id'])->assertJsonPath('data.status', 'written_off');

    $this->postJson(AR.'/allocations/'.$allocationId.'/reverse')->assertOk()->assertJsonPath('data.amount', '500.00');
    $this->postJson(AR.'/allocations/'.$allocationId.'/reverse')->assertUnprocessable();

    $this->getJson(AR.'/items/'.$item['id'])->assertJsonPath('data.status', 'open')->assertJsonPath('data.open_amount', '500.00')->assertJsonCount(1, 'data.allocations');
});

test('a debit adjustment raises the open amount', function (): void {
    receivableInvoice('100.00');
    $item = receivableItems()[0];

    $this->postJson(AR.'/allocations', ['allocations' => [['receivable_item_id' => $item['id'], 'type' => 'debit_adjustment', 'amount' => '20.00', 'value_date' => now()->toDateString()]]])->assertCreated();

    $this->getJson(AR.'/items/'.$item['id'])->assertJsonPath('data.open_amount', '120.00');
});

test('a disputed item is flagged and can be cleared', function (): void {
    receivableInvoice('100.00');
    $item = receivableItems()[0];

    $this->postJson(AR.'/items/'.$item['id'].'/dispute', ['disputed' => true])->assertUnprocessable();
    $this->postJson(AR.'/items/'.$item['id'].'/dispute', ['disputed' => true, 'reason' => 'Wrong quantity'])->assertOk()->assertJsonPath('data.status', 'disputed');
    $this->postJson(AR.'/items/'.$item['id'].'/dispute', ['disputed' => false])->assertOk()->assertJsonPath('data.status', 'open');
});

test('the aging puts an item in the bucket of its days past due and drills down to the items', function (): void {
    receivableInvoice('100.00');
    receivableInvoice('200.00');
    receivableInvoice('400.00');
    $items = ReceivableItem::withoutGlobalScopes()->where('customer_id', $this->customer->id)->orderBy('original_amount')->get();
    $items[0]->update(['due_date' => now()->addDays(5)->toDateString()]);
    $items[1]->update(['due_date' => now()->subDays(45)->toDateString()]);
    $items[2]->update(['due_date' => now()->subDays(200)->toDateString()]);

    $aging = $this->getJson(AR.'/aging')->assertOk()->json('data');

    expect($aging['totals']['current'])->toBe('100.00')
        ->and($aging['totals']['31_60'])->toBe('200.00')
        ->and($aging['totals']['121_plus'])->toBe('400.00')
        ->and($aging['over_90'])->toBe('400.00')
        ->and($aging['total_overdue'])->toBe('600.00')
        ->and($aging['total_open'])->toBe('700.00')
        ->and($aging['customers'][0]['customer_id'])->toBe($this->customer->id);

    $drill = $this->getJson(AR.'/items?bucket=31_60&customer_id='.$this->customer->id)->assertOk()->json('data');
    expect($drill)->toHaveCount(1)->and($drill[0]['open_amount'])->toBe('200.00')->and($drill[0]['status'])->toBe('overdue');
});

test('the DSO is open receivables over credit sales times the period, and undefined without sales', function (): void {
    expect($this->getJson(AR.'/dso')->assertOk()->json('data.dso'))->toBe('0.00');

    receivableInvoice('1000.00');

    $dso = $this->getJson(AR.'/dso?period_days=90')->assertOk()->json('data');
    expect($dso['dso'])->toBe('90.00')->and($dso['credit_sales'])->toBe('1000.00')->and($dso['receivables'])->toBe('1000.00');

    ReceivableItem::withoutGlobalScopes()->update(['issue_date' => now()->subDays(400)->toDateString()]);
    SalesInvoice::withoutGlobalScopes()->update(['date' => now()->subDays(400)->toDateString()]);

    expect($this->getJson(AR.'/dso?period_days=90')->assertOk()->json('data.dso'))->toBeNull();
});

test('a credit limit with a block-order policy blocks an operation that goes over it, and an override lets it through', function (): void {
    receivableInvoice('800.00');

    $this->postJson(AR.'/credit-limits', [
        'customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '1000.00', 'reaction_policy' => 'block_order', 'valid_from' => now()->subMonth()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.granted_limit', '1000.00');

    $query = '?customer_id='.$this->customer->id.'&scope=order';
    $this->getJson(AR.'/credit-check'.$query.'&amount=100')->assertOk()->assertJsonPath('data.outcome', 'ok');
    $this->getJson(AR.'/credit-check'.$query.'&amount=300')->assertOk()->assertJsonPath('data.outcome', 'block')->assertJsonPath('data.breaches.0', 'over_limit');
    $this->getJson(AR.'/credit-check?customer_id='.$this->customer->id.'&scope=delivery_note&amount=300')->assertOk()->assertJsonPath('data.outcome', 'warning');

    $this->postJson(AR.'/customers/'.$this->customer->id.'/overrides', ['scope' => 'order', 'reason' => 'Approved by the CFO'])->assertCreated();

    $this->getJson(AR.'/credit-check'.$query.'&amount=300')->assertOk()->assertJsonPath('data.outcome', 'ok')->assertJsonPath('data.overridden', true);
});

test('a block-delivery-note policy blocks delivery notes only, and credit hold blocks both', function (): void {
    receivableInvoice('900.00');
    $limit = fn (string $policy) => $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '1000.00', 'reaction_policy' => $policy, 'valid_from' => now()->toDateString()])->assertCreated();
    $outcome = fn (string $scope) => $this->getJson(AR.'/credit-check?customer_id='.$this->customer->id.'&scope='.$scope.'&amount=500')->json('data.outcome');

    $limit('block_delivery_note');
    expect($outcome('order'))->toBe('warning')->and($outcome('delivery_note'))->toBe('block');

    $limit('credit_hold');
    expect($outcome('order'))->toBe('block')->and($outcome('delivery_note'))->toBe('block');

    $limit('warning');
    expect($outcome('order'))->toBe('warning')->and($outcome('delivery_note'))->toBe('warning');
});

test('a new credit limit closes the one it replaces, and only the one in force counts', function (): void {
    $first = $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '500.00', 'reaction_policy' => 'warning', 'valid_from' => now()->subMonths(2)->toDateString()])->assertCreated()->json('data.id');
    $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '900.00', 'reaction_policy' => 'warning', 'valid_from' => now()->toDateString()])->assertCreated();

    $history = $this->getJson(AR.'/customers/'.$this->customer->id.'/credit-limits')->assertOk()->json('data');
    $closed = collect($history)->firstWhere('id', $first);

    expect($history)->toHaveCount(2)->and($closed['valid_to'])->toBe(now()->subDay()->toDateString());
    $this->getJson(AR.'/customers/'.$this->customer->id.'/credit')->assertOk()->assertJsonPath('data.limit.granted_limit', '900.00');
});

test('the credit position reports the exposure components, utilisation and the available credit', function (): void {
    receivableInvoice('400.00');
    $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '1000.00', 'insured_limit' => '1000.00', 'insurer_name' => 'Euler Hermes', 'policy_number' => 'P-1', 'coverage_percentage' => '80', 'reaction_policy' => 'warning', 'valid_from' => now()->toDateString()])->assertCreated();

    $this->getJson(AR.'/customers/'.$this->customer->id.'/credit')->assertOk()
        ->assertJsonPath('data.exposure.open_invoices', '400.00')
        ->assertJsonPath('data.exposure.total_exposure', '400.00')
        ->assertJsonPath('data.utilization_percent', '40.00')
        ->assertJsonPath('data.available_credit', '600.00')
        ->assertJsonPath('data.insured_cover', '800.00')
        ->assertJsonPath('data.uninsured_exposure', '0.00')
        ->assertJsonPath('data.is_blocked', false);
});

test('an insured limit without insurer or policy, or a period that ends before it starts, is refused', function (): void {
    $base = ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '1000.00', 'reaction_policy' => 'warning', 'valid_from' => now()->toDateString()];

    $this->postJson(AR.'/credit-limits', $base + ['insured_limit' => '500.00'])->assertUnprocessable()->assertJsonValidationErrors('policy_number');
    $this->postJson(AR.'/credit-limits', $base + ['valid_to' => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_to');
});

test('a revoked override blocks again', function (): void {
    receivableInvoice('900.00');
    $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '1000.00', 'reaction_policy' => 'credit_hold', 'valid_from' => now()->toDateString()])->assertCreated();
    $overrideId = $this->postJson(AR.'/customers/'.$this->customer->id.'/overrides', ['scope' => 'all', 'reason' => 'Goodwill', 'expires_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('data.id');
    $check = AR.'/credit-check?customer_id='.$this->customer->id.'&scope=order&amount=500';

    $this->getJson($check)->assertJsonPath('data.outcome', 'ok');
    $this->postJson(AR.'/overrides/'.$overrideId.'/revoke', ['reason' => 'Customer is late again'])->assertOk()->assertJsonPath('data.revoke_reason', 'Customer is late again');
    $this->getJson($check)->assertJsonPath('data.outcome', 'block');
});

test('the overdue rule of a credit limit raises a breach when the oldest overdue item is older than allowed', function (): void {
    receivableInvoice('300.00');
    ReceivableItem::withoutGlobalScopes()->update(['due_date' => now()->subDays(40)->toDateString()]);
    $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '100000.00', 'max_overdue_days' => 30, 'overdue_tolerance_amount' => '100.00', 'reaction_policy' => 'block_order', 'valid_from' => now()->toDateString()])->assertCreated();

    $this->getJson(AR.'/credit-check?customer_id='.$this->customer->id.'&scope=order')->assertOk()
        ->assertJsonPath('data.outcome', 'block')->assertJsonPath('data.breaches.0', 'overdue_days');
});

test('the risk score grows with the overdue share and the days past due', function (): void {
    receivableInvoice('1000.00');
    $calm = $this->getJson(AR.'/customers/'.$this->customer->id.'/risk')->assertOk()->json('data');

    ReceivableItem::withoutGlobalScopes()->update(['due_date' => now()->subDays(130)->toDateString()]);
    $late = $this->getJson(AR.'/customers/'.$this->customer->id.'/risk')->assertOk()->json('data');

    expect($calm['rating'])->toBe('low')->and($late['score'])->toBeGreaterThan($calm['score'])
        ->and(collect($late['factors'])->firstWhere('key', 'max_days_past_due')['points'])->toBe(25.0);
});

test('the provision applies the default rates by bucket and the configured ones win', function (): void {
    receivableInvoice('1000.00');
    ReceivableItem::withoutGlobalScopes()->update(['due_date' => now()->subDays(200)->toDateString()]);

    $default = $this->getJson(AR.'/provision')->assertOk()->json('data');
    expect($default['total_provision'])->toBe('500.00')->and($default['net_realizable_value'])->toBe('500.00');

    $this->putJson(AR.'/provision/rates', ['rates' => ['121_plus' => '80']])->assertOk()->assertJsonPath('data.121_plus', '80.00');

    $this->getJson(AR.'/provision')->assertJsonPath('data.total_provision', '800.00')->assertJsonPath('data.net_realizable_value', '200.00');
    $this->putJson(AR.'/provision/rates', ['rates' => ['nonexistent' => '10']])->assertOk();
});

test('the ledger runs a balance over the documents and their settlements', function (): void {
    receivableInvoice('1000.00');
    $item = receivableItems()[0];
    $this->postJson(AR.'/allocations', ['allocations' => [['receivable_item_id' => $item['id'], 'type' => 'discount', 'amount' => '300.00', 'value_date' => now()->toDateString()]]])->assertCreated();

    $ledger = $this->getJson(AR.'/customers/'.$this->customer->id.'/ledger')->assertOk()->json('data');

    expect($ledger['movements'])->toHaveCount(2)
        ->and($ledger['movements'][0]['balance'])->toBe('1000.00')
        ->and($ledger['movements'][1]['kind'])->toBe('discount')
        ->and($ledger['movements'][1]['balance'])->toBe('700.00')
        ->and($ledger['closing_balance'])->toBe('700.00');
});

test('the statement has opening balance, movements, open items, aging and closing balance, and exports to PDF and Excel', function (): void {
    receivableInvoice('1000.00');
    $url = AR.'/customers/'.$this->customer->id.'/statement';

    $statement = $this->getJson($url.'?from='.now()->subMonth()->toDateString().'&to='.now()->toDateString())->assertOk()->json('data');
    expect($statement['opening_balance'])->toBe('0.00')->and($statement['closing_balance'])->toBe('1000.00')
        ->and($statement['open_items'])->toHaveCount(1)->and($statement['total_open'])->toBe('1000.00')->and($statement['aging']['totals']['current'])->toBe('1000.00');

    $later = $this->getJson($url.'?from='.now()->addDay()->toDateString().'&to='.now()->addWeek()->toDateString())->assertOk()->json('data');
    expect($later['opening_balance'])->toBe('1000.00')->and($later['movements'])->toBe([]);

    $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get($url.'/xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('sending a statement queues the dispatch to the customer email or to the given recipient', function (): void {
    Queue::fake();
    $url = AR.'/customers/'.$this->customer->id.'/statement/send';
    $period = ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()];

    $this->postJson($url, $period)->assertUnprocessable()->assertJsonValidationErrors('recipient');

    $this->postJson($url, $period + ['recipient' => 'ap@bianchi.test', 'locale' => 'de'])->assertAccepted()->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(SendCustomerStatementJob::class, 1);
    $this->assertDatabaseHas('customer_statement_dispatches', ['customer_id' => $this->customer->id, 'recipient' => 'ap@bianchi.test', 'locale' => 'de', 'status' => 'queued']);
});

test('the dashboard summarises exposure, overdue, DSO, provision and blocked customers', function (): void {
    receivableInvoice('1000.00');
    ReceivableItem::withoutGlobalScopes()->update(['due_date' => now()->subDays(100)->toDateString()]);
    $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '500.00', 'reaction_policy' => 'credit_hold', 'valid_from' => now()->toDateString()])->assertCreated();

    $kpis = $this->getJson(AR.'/dashboard')->assertOk()->assertJsonPath('data.blocked.0.customer_id', $this->customer->id)->json('data.kpis');

    expect($kpis['total_exposure'])->toBe('1000.00')->and($kpis['overdue_amount'])->toBe('1000.00')->and($kpis['over_90'])->toBe('1000.00')
        ->and($kpis['blocked_customers'])->toBe(1)->and($kpis['dso'])->toBe('90.00')->and((float) $kpis['bad_debt_provision'])->toBeGreaterThan(0.0);
});

test('a customer or an item of another company answers 404 and nothing leaks across companies', function (): void {
    $foreign = billingInTenant($this->otherTenant, fn () => Contact::query()->where('company_name', 'Neri Srl')->firstOrFail());

    $this->getJson(AR.'/customers/'.$foreign->id.'/ledger')->assertNotFound();
    $this->getJson(AR.'/customers/'.$foreign->id.'/credit')->assertNotFound();
    $this->getJson(AR.'/customers/'.$foreign->id.'/statement')->assertNotFound();
    $this->postJson(AR.'/customers/'.$foreign->id.'/overrides', ['scope' => 'all', 'reason' => 'Because'])->assertNotFound();
    $this->postJson(AR.'/credit-limits', ['customer_id' => $foreign->id, 'currency' => 'EUR', 'granted_limit' => '1.00', 'reaction_policy' => 'warning', 'valid_from' => now()->toDateString()])->assertUnprocessable();
});

test('only credit managers change credit data', function (): void {
    receivableInvoice('100.00');
    $item = receivableItems()[0];
    billingActor($this, 'viewer');

    $this->postJson(AR.'/allocations', ['allocations' => [['receivable_item_id' => $item['id'], 'type' => 'discount', 'amount' => '1.00', 'value_date' => now()->toDateString()]]])->assertForbidden();
    $this->postJson(AR.'/credit-limits', ['customer_id' => $this->customer->id, 'currency' => 'EUR', 'granted_limit' => '1.00', 'reaction_policy' => 'warning', 'valid_from' => now()->toDateString()])->assertForbidden();
    $this->putJson(AR.'/provision/rates', ['rates' => ['current' => '1']])->assertForbidden();
});

test('the credit note of an invoice takes the items it covers off the open amount', function (): void {
    $invoice = receivableInvoice('1000.00', 3);
    expect(receivableItems())->toHaveCount(3);

    app(InvoicingService::class)->issueCreditNote($invoice, [], [['description' => 'Credit', 'quantity' => 1, 'unit_price' => '400.00', 'tax_rate' => 0]]);

    $items = receivableItems();
    $total = array_reduce($items, fn (string $sum, array $item): string => bcadd($sum, $item['open_amount'], 2), '0.00');
    expect($items)->toHaveCount(2)->and($total)->toBe('600.00');
});
