<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

const SRM_API = '/api/v1/procurement';

beforeEach(function (): void {
    matchingSetUp($this);
});

/**
 * A late, fully received order of the supplier whose invoice is blocked by a price variance.
 *
 * @return array{order: PurchaseOrder|Modules\Procurement\Models\PurchaseOrder, invoice: PassiveInvoice}
 */
function srmLateBlockedOrder(object $test): array
{
    $order = Fixtures::order($test->tenant, $test->supplier, $test->poLines, [
        'date' => now()->toDateString(),
        'expected_delivery_date' => now()->subDays(10)->toDateString(),
    ]);
    Fixtures::receive($test->tenant, $order, [1 => 10, 2 => 5], (string) $test->owner->id);
    $invoice = Fixtures::invoice($test->tenant, $test->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);
    matchingRun($test, $invoice, (string) $order->id);

    return ['order' => $order, 'invoice' => $invoice];
}

it('requires authentication on every SRM endpoint', function (): void {
    $this->refreshApplication();
    $headers = ['X-Tenant-Id' => (string) $this->tenant->id];

    $this->withHeaders($headers)->getJson(SRM_API.'/srm/dashboard')->assertUnauthorized();
    $this->withHeaders($headers)->getJson(SRM_API.'/srm/scorecards')->assertUnauthorized();
    $this->withHeaders($headers)->getJson(SRM_API.'/suppliers/'.Str::uuid7().'/srm-scorecard')->assertUnauthorized();
});

it('summarises what is late and what is blocked on the dashboard', function (): void {
    srmLateBlockedOrder($this);

    $data = $this->getJson(SRM_API.'/srm/dashboard')->assertOk()->json('data');

    expect($data['orders']['overdue'])->toBe(1)
        ->and($data['orders']['overdue_list'][0]['supplier_name'])->toBe($this->supplier->company_name)
        ->and($data['blocked_invoices']['count'])->toBe(1)
        ->and($data['blocked_invoices']['items'][0]['status'])->toBe('blocked_discrepancy')
        ->and((float) $data['blocked_invoices']['variance_total'])->toBe(100.0)
        ->and($data['requisitions']['pending_approval'])->toBe(0)
        ->and($data['rfqs']['open'])->toBe(0)
        ->and($data['spend']['by_supplier'][0]['label'])->toBe($this->supplier->company_name)
        ->and($data['suppliers']['by_qualification'])->not->toBeEmpty();
});

it('totals the open order value per currency without mixing them', function (): void {
    Fixtures::order($this->tenant, $this->supplier, [['Fan', 1, '100.00']], ['currency' => 'EUR', 'expected_delivery_date' => now()->addDays(5)->toDateString()]);
    Fixtures::order($this->tenant, $this->supplier, [['Fan', 1, '50.00']], ['currency' => 'CHF', 'expected_delivery_date' => now()->addDays(5)->toDateString()]);

    $orders = $this->getJson(SRM_API.'/srm/dashboard')->assertOk()->json('data.orders');

    expect($orders['open'])->toBe(2)
        ->and($orders['open_value'])->toBe(['CHF' => '50.00', 'EUR' => '100.00'])
        ->and($orders['overdue'])->toBe(0);
});

it('rates a late supplier with a blocked invoice into tier D', function (): void {
    srmLateBlockedOrder($this);

    $rating = $this->getJson(SRM_API."/suppliers/{$this->supplier->id}/srm-scorecard")->assertOk()->json('data');

    // On-time delivery 0 (weight 35), quality 100 (30) and invoice accuracy 0 (10); price and tenders have no data.
    expect($rating['components']['on_time_delivery_rate'])->toBe('0.00')
        ->and($rating['components']['quality_acceptance_rate'])->toBe('100.00')
        ->and($rating['components']['invoice_accuracy_rate'])->toBe('0.00')
        ->and($rating['components']['rfq_responsiveness_rate'])->toBeNull()
        ->and($rating['score'])->toBe('40.00')
        ->and($rating['tier'])->toBe('D')
        ->and($rating['sample']['blocked_invoices'])->toBe(1)
        ->and($rating['weights']['on_time_delivery_rate'])->toBe(35);
});

it('ranks the suppliers best first and lists the weakest in the dashboard', function (): void {
    srmLateBlockedOrder($this);
    $punctual = Fixtures::supplier($this->tenant);
    $order = Fixtures::order($this->tenant, $punctual, [['Fan', 2, '10.00']], [
        'date' => now()->toDateString(),
        'expected_delivery_date' => now()->addDays(3)->toDateString(),
    ]);
    Fixtures::receive($this->tenant, $order, [1 => 2], (string) $this->owner->id);

    $ranking = $this->getJson(SRM_API.'/srm/scorecards')->assertOk()->json('data');

    expect($ranking)->toHaveCount(2)
        ->and($ranking[0]['supplier_id'])->toBe((string) $punctual->id)
        ->and($ranking[0]['tier'])->toBe('A')
        ->and($ranking[1]['supplier_id'])->toBe((string) $this->supplier->id);

    $scorecards = $this->getJson(SRM_API.'/srm/dashboard')->assertOk()->json('data.scorecards');

    expect($scorecards['top'][0]['supplier_id'])->toBe((string) $punctual->id)
        ->and($scorecards['bottom'][0]['supplier_id'])->toBe((string) $this->supplier->id);
});

it('leaves a supplier without any data unscored instead of penalising it', function (): void {
    $rating = $this->getJson(SRM_API."/suppliers/{$this->supplier->id}/srm-scorecard")->assertOk()->json('data');

    expect($rating['score'])->toBeNull()
        ->and($rating['tier'])->toBeNull();
});

it('validates the period of the SRM endpoints', function (): void {
    $this->getJson(SRM_API.'/srm/dashboard?from=2026-12-31&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->getJson(SRM_API.'/srm/scorecards?from=31-12-2026')->assertUnprocessable()->assertJsonValidationErrors(['from']);
});

it('keeps the SRM figures of a company away from the others', function (): void {
    $mine = srmLateBlockedOrder($this);

    treasuryActor($this, 'owner', $this->otherTenant);

    $data = $this->getJson(SRM_API.'/srm/dashboard')->assertOk()->json('data');

    expect($data['orders']['open'])->toBe(0)
        ->and($data['blocked_invoices']['count'])->toBe(0)
        ->and($data['scorecards']['top'])->toBe([]);
    $this->getJson(SRM_API."/suppliers/{$this->supplier->id}/srm-scorecard")->assertNotFound();

    expect($mine['order']->id)->not->toBeNull();
});

/**
 * Three suppliers with one on-time order each (names A..C) and a fourth late one, so the ranking has two tiers.
 *
 * @return list<Supplier>
 */
function srmFourSuppliers(object $test): array
{
    $suppliers = [];

    foreach (['Alfa Forniture', 'Beta Ricambi', 'Gamma Logistica'] as $name) {
        $supplier = Fixtures::supplier($test->tenant);
        $supplier->update(['company_name' => $name]);
        $order = Fixtures::order($test->tenant, $supplier, [['Fan', 2, '10.00']], ['date' => now()->toDateString(), 'expected_delivery_date' => now()->addDays(3)->toDateString()]);
        Fixtures::receive($test->tenant, $order, [1 => 2], (string) $test->owner->id);
        $suppliers[] = $supplier;
    }

    srmLateBlockedOrder($test);
    $test->supplier->update(['company_name' => 'Delta Ritardi']);

    return $suppliers;
}

it('paginates the ranking and reports the page meta', function (): void {
    srmFourSuppliers($this);

    $first = $this->getJson(SRM_API.'/srm/scorecards?per_page=3&page=1')->assertOk();
    $second = $this->getJson(SRM_API.'/srm/scorecards?per_page=3&page=2')->assertOk();

    expect($first->json('data'))->toHaveCount(3)
        ->and($first->json('meta'))->toMatchArray(['current_page' => 1, 'per_page' => 3, 'total' => 4, 'last_page' => 2, 'from' => 1, 'to' => 3])
        ->and($second->json('data'))->toHaveCount(1)
        ->and($second->json('data.0.supplier_name'))->toBe('Delta Ritardi')
        ->and($second->json('meta.from'))->toBe(4);
});

it('filters the ranking by name and tier', function (): void {
    srmFourSuppliers($this);

    $byName = $this->getJson(SRM_API.'/srm/scorecards?search=beta')->assertOk();
    $byTier = $this->getJson(SRM_API.'/srm/scorecards?tier=D')->assertOk();

    expect($byName->json('data'))->toHaveCount(1)
        ->and($byName->json('data.0.supplier_name'))->toBe('Beta Ricambi')
        ->and($byTier->json('meta.total'))->toBe(1)
        ->and($byTier->json('data.0.supplier_name'))->toBe('Delta Ritardi');
});

it('sorts the ranking by name and by a component with empty values last', function (): void {
    srmFourSuppliers($this);

    $names = collect($this->getJson(SRM_API.'/srm/scorecards?sort=name&direction=asc')->assertOk()->json('data'))->pluck('supplier_name')->all();
    $ontime = collect($this->getJson(SRM_API.'/srm/scorecards?sort=on_time_delivery_rate&direction=asc')->assertOk()->json('data'))->pluck('supplier_name')->all();
    $rfq = collect($this->getJson(SRM_API.'/srm/scorecards?sort=rfq_responsiveness_rate&direction=desc')->assertOk()->json('data'))->pluck('components.rfq_responsiveness_rate')->all();

    expect($names)->toBe(['Alfa Forniture', 'Beta Ricambi', 'Delta Ritardi', 'Gamma Logistica'])
        ->and($ontime[0])->toBe('Delta Ritardi')
        ->and($rfq)->toBe([null, null, null, null]);
});

it('validates the listing parameters of the ranking', function (): void {
    $this->getJson(SRM_API.'/srm/scorecards?tier=Z')->assertUnprocessable()->assertJsonValidationErrors(['tier']);
    $this->getJson(SRM_API.'/srm/scorecards?sort=colour')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
    $this->getJson(SRM_API.'/srm/scorecards?per_page=500')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
});

it('clamps an out-of-range page to the last one', function (): void {
    srmFourSuppliers($this);

    $this->getJson(SRM_API.'/srm/scorecards?per_page=3&page=99')->assertOk()->assertJsonPath('meta.current_page', 2);
});
