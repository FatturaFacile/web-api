<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Intercompany\Models\ReconciliationItem;

require_once __DIR__.'/IntercompanySupport.php';

uses(RefreshDatabase::class);

/**
 * The reciprocal reconciliation cockpit: matching, the anomaly classes (timing, FX, amount, tax, missing counterpart), the
 * manual actions and the quadrature matrix. The group currency is EUR and the amount tolerance 1.00, the date tolerance 5 days.
 */
beforeEach(function (): void {
    icGroupSetUp($this);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Runs the reconciliation at a date and returns the items keyed by the number of the document of the first entity.
 *
 * @return array<string, array<string, mixed>>
 */
function icReconcile(string $asOf = '2027-03-31'): array
{
    $test = test();
    $batch = $test->postJson("/api/v1/intercompany/groups/{$test->group->id}/reconciliation/batches", ['as_of_date' => $asOf])->assertCreated()->json('data');
    $items = $test->getJson("/api/v1/intercompany/reconciliation/batches/{$batch['id']}")->assertOk()->json('data.items');

    return collect($items)->keyBy(fn (array $item): string => (string) ($item['a_document_number'] ?? $item['b_document_number']))->all();
}

/**
 * Books the counterpart of an invoice in the subsidiary the way a mirror would, posted unless said otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function icBook(object $test, string $number, array $overrides = []): PassiveInvoice
{
    return icPassive($test->italia, $test->parentAsSupplier, array_merge(['invoice_number' => $number, 'gl_journal_entry_id' => (string) Str::uuid7()], $overrides));
}

test('a posted counterpart with the same number and amount matches exactly', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-1']);
    icBook($this, 'FT-1');

    $item = icReconcile()['FT-1'];

    expect($item['status'])->toBe('matched')->and($item['anomaly_type'])->toBe('none')->and($item['match_kind'])->toBe('exact')
        ->and($item['difference'])->toBe('0.0000');
});

test('a counterpart within the tolerance of amount and date matches by tolerance', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-2']);
    icBook($this, 'SUPPLIER-REF-77', ['total_amount' => 1220.40, 'date' => '2027-03-12']);

    $item = icReconcile()['FT-2'];

    expect($item['status'])->toBe('matched')->and($item['match_kind'])->toBe('tolerance')->and($item['b_document_number'])->toBe('SUPPLIER-REF-77');
});

test('a mirror that is still a draft is a timing difference', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-3']);
    icScan($this);

    $item = icReconcile()['FT-3'];

    expect($item['status'])->toBe('unmatched')->and($item['anomaly_type'])->toBe('timing')->and($item['anomaly_reason'])->toBe('mirror_draft');
});

test('a document not yet booked is in transit when recent and missing when old', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'RECENT', 'date' => '2027-03-29']);
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'OLD', 'date' => '2027-02-01']);

    $items = icReconcile();

    expect($items['RECENT']['anomaly_type'])->toBe('timing')->and($items['RECENT']['anomaly_reason'])->toBe('in_transit')
        ->and($items['OLD']['anomaly_type'])->toBe('missing_counterpart')->and($items['OLD']['anomaly_reason'])->toBe('missing_in_counterparty');
});

test('a different gross amount with the same net is a tax difference', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-4']);
    icBook($this, 'FT-4', ['total_amount' => 1180, 'total_tax' => 180]);

    $item = icReconcile()['FT-4'];

    expect($item['anomaly_type'])->toBe('tax')->and($item['difference'])->toBe('40.0000');
});

test('a different net amount is an amount difference', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-5']);
    icBook($this, 'FT-5', ['total_amount' => 1000, 'total_net' => 820, 'total_tax' => 180]);

    $item = icReconcile()['FT-5'];

    expect($item['anomaly_type'])->toBe('amount')->and($item['difference'])->toBe('220.0000');
});

test('documents in different currencies are an exchange difference', function (): void {
    icRates();
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-6']);
    icBook($this, 'FT-6', ['currency' => 'CHF', 'exchange_rate' => 0.95, 'total_amount' => 1220, 'total_net' => 1000, 'total_tax' => 220]);

    $item = icReconcile('2027-07-31')['FT-6'];

    expect($item['anomaly_type'])->toBe('fx')->and($item['anomaly_reason'])->toBe('currency_mismatch')
        ->and($item['difference_group_currency'])->not->toBe('0.0000');
});

test('a supplier document that no invoice explains is reported as missing in the issuer', function (): void {
    icBook($this, 'ORPHAN-1');

    $item = icReconcile()['ORPHAN-1'];

    expect($item['anomaly_type'])->toBe('missing_counterpart')->and($item['anomaly_reason'])->toBe('missing_in_issuer')->and($item['a_document_id'])->toBeNull();
});

test('an item is matched by hand, unmatched, disputed, written off and adjusted', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-7', 'date' => '2027-01-10']);
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-8', 'date' => '2027-01-11']);
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-9', 'date' => '2027-01-12']);
    $counterpart = icBook($this, 'MANUAL-1', ['date' => '2027-02-20']);

    $items = icReconcile();
    expect($items['FT-7']['anomaly_type'])->toBe('missing_counterpart');

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-7']['id']}/match", ['counterpart_document_id' => $counterpart->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'matched')
        ->assertJsonPath('data.match_kind', 'manual')
        ->assertJsonPath('data.b_document_number', 'MANUAL-1');

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-7']['id']}/unmatch")
        ->assertOk()
        ->assertJsonPath('data.status', 'unmatched')
        ->assertJsonPath('data.b_document_id', null);

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-8']['id']}/dispute", ['reason' => 'Goods not received'])
        ->assertOk()->assertJsonPath('data.status', 'disputed')->assertJsonPath('data.resolution_reason', 'Goods not received');

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-9']['id']}/write-off", ['reason' => 'Agreed with the subsidiary'])
        ->assertOk()->assertJsonPath('data.status', 'written_off');

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-9']['id']}/adjust", ['reason' => 'Late correction', 'adjustment_amount' => 10])
        ->assertStatus(409)->assertJsonPath('error_code', 'intercompany.error.item_closed');

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-8']['id']}/adjust", ['reason' => 'Corrected by credit note', 'adjustment_amount' => 1220])
        ->assertOk()->assertJsonPath('data.status', 'adjusted')->assertJsonPath('data.adjustment_amount', '1220.0000');
});

test('a counterpart already matched in the batch cannot be matched twice', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-10']);
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-11']);
    icBook($this, 'FT-10');
    $items = icReconcile();

    $this->postJson("/api/v1/intercompany/reconciliation/items/{$items['FT-11']['id']}/match", ['counterpart_document_id' => $items['FT-10']['b_document_id']])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'intercompany.error.counterpart_taken');
});

test('the batch lists its totals and can be filtered by anomaly', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-12']);
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-13', 'date' => '2027-01-02']);
    icBook($this, 'FT-12');

    $batch = $this->postJson("/api/v1/intercompany/groups/{$this->group->id}/reconciliation/batches", ['as_of_date' => '2027-03-31'])->assertCreated()
        ->assertJsonPath('data.totals.items', 2)
        ->assertJsonPath('data.totals.matched', 1)
        ->assertJsonPath('data.totals.unmatched', 1)
        ->json('data');

    $this->getJson("/api/v1/intercompany/reconciliation/batches/{$batch['id']}?anomaly_type=missing_counterpart")
        ->assertOk()
        ->assertJsonCount(1, 'data.items');

    expect(ReconciliationItem::withoutGlobalScopes()->where('batch_id', $batch['id'])->count())->toBe(2);
});

test('the quadrature matrix compares receivables and payables for every pair', function (): void {
    icInvoice($this->parent, $this->italiaAsCustomer, ['invoice_number' => 'FT-14']);
    icBook($this, 'FT-14', ['total_amount' => 1000, 'total_net' => 820, 'total_tax' => 180]);
    icReconcile();

    $matrix = $this->getJson("/api/v1/intercompany/groups/{$this->group->id}/reconciliation/matrix?as_of_date=2027-03-31")->assertOk()->json('data');
    $cell = collect($matrix['cells'])->firstWhere('entity_a_tenant_id', $this->parent->id);

    expect($matrix['currency'])->toBe('EUR')
        ->and($matrix['entities'])->toHaveCount(2)
        ->and($cell['receivable_a'])->toBe('1220.0000')
        ->and($cell['payable_b'])->toBe('1000.0000')
        ->and($cell['difference'])->toBe('220.0000')
        ->and($cell['status'])->toBe('difference')
        ->and($cell['anomaly_counts']['amount'])->toBe(1)
        ->and($cell['open_items'])->toBe(1);
});
