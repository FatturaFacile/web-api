<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Infrastructure\Jobs\NightlyPredictiveScoringJob;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;

require_once __DIR__.'/AiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->company = ocrCompany('CH');
    $this->tenant = $this->company['tenant'];
});

function aiPassiveInvoice(Tenant $tenant, string $supplier, string $number, float $amount, ?string $iban = null, ?string $date = null): string
{
    $id = (string) Str::uuid();
    $qr = $iban === null ? null : "SPC\n0200\n1\n{$iban}\nK\nMuster AG";
    DB::table('passive_invoices')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'supplier_id' => $supplier, 'invoice_number' => $number, 'date' => $date ?? now()->subDays(3)->toDateString(), 'total_amount' => $amount, 'qr_raw_payload' => $qr, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function aiSalesInvoice(Tenant $tenant, string $contact, string $number, float $taxRate, ?string $dueDate, string $jurisdiction = 'CH'): string
{
    $id = (string) Str::uuid();
    DB::table('invoices')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'contact_id' => $contact, 'invoice_number' => $number, 'date' => now()->subDays(2)->toDateString(), 'due_date' => $dueDate, 'total_gross' => 108.1, 'jurisdiction' => $jurisdiction, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('invoice_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'invoice_id' => $id, 'description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => $taxRate, 'line_total' => 100, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/**
 * @return list<array<string, mixed>>
 */
function aiOpenAnomalies(mixed $test): array
{
    return $test->getJson('/api/v1/ai/insights')->assertOk()->json('data.anomalies');
}

test('a duplicate supplier invoice and a changed payment IBAN are flagged before the invoice is paid', function (): void {
    ocrActAs($this->tenant);
    $first = aiPassiveInvoice($this->tenant, $this->company['vendor'], 'FT-2026/17', 250.0, 'CH9300762011623852957');
    $second = aiPassiveInvoice($this->tenant, $this->company['vendor'], 'ft 2026 17', 250.0, 'CH5604835012345678009');

    $this->postJson('/api/v1/ai/insights/generate')->assertOk();

    $found = collect(aiOpenAnomalies($this))->keyBy(fn (array $row): string => $row['proposed_payload']['rule'].':'.$row['proposed_payload']['subject_id']);

    expect($found->keys()->all())->toContain("duplicate_invoice:{$second}", "iban_change:{$second}", "duplicate_invoice:{$first}")
        ->and($found["iban_change:{$second}"]['priority'])->toBe('high')
        ->and($found["iban_change:{$second}"]['proposed_payload']['severity'])->toBe('critical')
        ->and($found["iban_change:{$second}"]['proposed_payload']['details']['invoice_iban'])->toBe('CH56*************8009')
        ->and($found["iban_change:{$second}"]['proposed_payload']['details']['master_iban'])->toBe('CH93*************2957')
        ->and(json_encode($found["iban_change:{$second}"]))->not->toContain('CH5604835012345678009')
        ->and($found->has("iban_change:{$first}"))->toBeFalse();
});

test('an invoice with the statutory rate, a due date and a known customer raises nothing', function (): void {
    ocrActAs($this->tenant);
    aiSalesInvoice($this->tenant, $this->company['vendor'], 'RE-1', 8.1, now()->addDays(30)->toDateString());
    aiPassiveInvoice($this->tenant, $this->company['vendor'], 'FT-1', 100.0, 'CH9300762011623852957');

    $this->postJson('/api/v1/ai/insights/generate')->assertOk()->assertJsonPath('data.anomalies', 0);
});

test('a VAT rate that is not statutory and missing fields before a QR-bill or an SDI invoice are flagged', function (): void {
    ocrActAs($this->tenant);
    $old = aiSalesInvoice($this->tenant, $this->company['vendor'], 'RE-2', 7.7, null);
    $italian = aiSalesInvoice($this->tenant, $this->company['vendor'], 'RE-3', 22, now()->addDays(30)->toDateString(), 'IT');
    DB::table('contacts')->where('id', $this->company['vendor'])->update(['country' => 'IT', 'vat_number' => null, 'fiscal_code' => null]);

    $this->postJson('/api/v1/ai/insights/generate')->assertOk();

    $found = collect(aiOpenAnomalies($this))->keyBy(fn (array $row): string => $row['proposed_payload']['rule'].':'.$row['proposed_payload']['subject_id']);

    expect($found["vat_rate_mismatch:{$old}"]['proposed_payload']['details']['items'][0]['tax_rate'])->toBe(7.7)
        ->and($found["missing_statutory_fields:{$old}"]['proposed_payload']['details']['missing'])->toBe(['due_date'])
        ->and($found["missing_statutory_fields:{$old}"]['proposed_payload']['details']['target'])->toBe('Swiss QR-bill')
        ->and($found["missing_statutory_fields:{$italian}"]['proposed_payload']['details']['missing'])->toBe(['customer_vat_or_fiscal_code', 'sdi_code_or_pec'])
        ->and($found["missing_statutory_fields:{$italian}"]['proposed_payload']['details']['target'])->toBe('SDI XML');
});

test('scanning twice does not pile up the same alert, and a person can dismiss it as a false positive', function (): void {
    ocrActAs($this->tenant);
    aiSalesInvoice($this->tenant, $this->company['vendor'], 'RE-4', 7.7, now()->addDays(10)->toDateString());

    $this->postJson('/api/v1/ai/insights/generate')->assertOk();
    $this->postJson('/api/v1/ai/insights/generate')->assertOk();

    $anomalies = aiOpenAnomalies($this);

    expect($anomalies)->toHaveCount(1);

    $this->postJson('/api/v1/ai/suggestions/'.$anomalies[0]['id'].'/reject', ['reason' => 'Old contract rate, correct'])->assertOk();

    expect(aiOpenAnomalies($this))->toBe([]);
});

test('the customers whose order frequency dropped are flagged for retention', function (): void {
    ocrActAs($this->tenant);

    foreach ([170, 150, 130, 110] as $daysAgo) {
        DB::table('invoices')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'contact_id' => $this->company['vendor'], 'invoice_number' => 'OLD-'.$daysAgo, 'date' => now()->subDays($daysAgo)->toDateString(), 'total_gross' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('invoices')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'contact_id' => $this->company['vendor'], 'invoice_number' => 'NEW-1', 'date' => now()->subDays(20)->toDateString(), 'total_gross' => 1000, 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson('/api/v1/ai/insights/generate')->assertOk()->assertJsonPath('data.churn', 1);

    $item = collect($this->getJson('/api/v1/ai/insights')->json('data.recommendations'))->firstWhere('kind', 'churn_risk');

    expect($item['proposed_payload']['customer_id'])->toBe($this->company['vendor'])
        ->and($item['proposed_payload']['invoices_prior_90_days'])->toBe(4)
        ->and($item['proposed_payload']['invoices_last_90_days'])->toBe(1)
        ->and($item['amount'])->toBe(4000);
});

test('a customer with a deteriorating payment behaviour is flagged for an early reminder', function (): void {
    ocrActAs($this->tenant);
    aiInTenant($this->tenant, fn () => PaymentBehaviorModel::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->company['vendor'], 'sample_size' => 12, 'average_days_late' => 34, 'std_dev_days_late' => 6, 'on_time_payment_probability' => 0.1, 'predicted_collection_date_offset_days' => 34, 'payment_risk_tier' => PaymentRiskTier::High, 'last_trained_at' => now()]));

    $this->postJson('/api/v1/ai/insights/generate')->assertOk()->assertJsonPath('data.late_payment', 1);

    $item = collect($this->getJson('/api/v1/ai/insights')->json('data.recommendations'))->firstWhere('kind', 'late_payment_risk');

    expect($item['proposed_payload']['risk_tier'])->toBe('high')
        ->and($item['proposed_payload']['recommended_action'])->toBe('send_payment_reminder')
        ->and($item['proposed_payload']['average_days_late'])->toBe(34);
});

test('an item at or below its reorder point is recommended for reorder, one above it is not', function (): void {
    ocrActAs($this->tenant);
    $now = now();
    [$warehouse, $zone, $aisle, $rack, $bin] = array_map(static fn (): string => (string) Str::uuid(), range(1, 5));
    DB::table('warehouses')->insert(['id' => $warehouse, 'tenant_id' => $this->tenant->id, 'code' => 'W1', 'name' => 'Main', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('warehouse_zones')->insert(['id' => $zone, 'tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse, 'code' => 'Z1', 'name' => 'Zone', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('warehouse_aisles')->insert(['id' => $aisle, 'tenant_id' => $this->tenant->id, 'warehouse_zone_id' => $zone, 'code' => 'A1', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('warehouse_racks')->insert(['id' => $rack, 'tenant_id' => $this->tenant->id, 'warehouse_aisle_id' => $aisle, 'code' => 'R1', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('warehouse_bins')->insert(['id' => $bin, 'tenant_id' => $this->tenant->id, 'warehouse_rack_id' => $rack, 'code' => 'B1', 'barcode' => 'BIN-1', 'created_at' => $now, 'updated_at' => $now]);
    $category = (string) Str::uuid();
    DB::table('stock_item_categories')->insert(['id' => $category, 'tenant_id' => $this->tenant->id, 'code' => 'C1', 'name' => 'Parts', 'created_at' => now(), 'updated_at' => now()]);

    foreach (['LOW' => 3, 'OK' => 80] as $sku => $onHand) {
        $item = (string) Str::uuid();
        DB::table('stock_items')->insert(['id' => $item, 'tenant_id' => $this->tenant->id, 'category_id' => $category, 'sku' => $sku, 'name' => "Part {$sku}", 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_reorder_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse, 'stock_item_id' => $item, 'min_stock_level' => 5, 'max_stock_level' => 100, 'reorder_point' => 10, 'economic_order_quantity' => 40, 'lead_time_days' => 12, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_quants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'warehouse_bin_id' => $bin, 'stock_item_id' => $item, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->postJson('/api/v1/ai/insights/generate')->assertOk()->assertJsonPath('data.reorder', 1);

    $items = collect($this->getJson('/api/v1/ai/insights')->json('data.recommendations'))->where('kind', 'reorder');

    expect($items)->toHaveCount(1)
        ->and($items->first()['proposed_payload']['sku'])->toBe('LOW')
        ->and($items->first()['proposed_payload']['suggested_quantity'])->toBe(40)
        ->and($items->first()['proposed_payload']['lead_time_days'])->toBe(12);
});

test('a cash balance that turns negative in the pessimistic projection raises a cash alert', function (): void {
    ocrActAs($this->tenant);
    $days = [];

    foreach ([0 => [5000, 6000, 7000], 1 => [1500, 3000, 4500], 2 => [-2500, 1200, 3800]] as $offset => [$p10, $p50, $p90]) {
        $days[] = ['date' => now()->addDays($offset)->toDateString(), 'balance' => ['p10' => $p10, 'p50' => $p50, 'p90' => $p90], 'statutory_outflows' => []];
    }

    aiInTenant($this->tenant, fn () => LiquidityForecastRun::create(['tenant_id' => $this->tenant->id, 'generated_at' => now(), 'horizon_days' => 91, 'currency' => 'CHF', 'starting_cash_balance' => 5000, 'daily_projections_json' => $days, 'key_drivers_json' => []]));

    $this->postJson('/api/v1/ai/insights/generate')->assertOk()->assertJsonPath('data.cash_flow', 1);

    $response = $this->getJson('/api/v1/ai/insights')->assertOk()->assertJsonPath('data.cash_flow.currency', 'CHF')->assertJsonPath('data.cash_flow.p10.2', -2500);
    $alert = collect($response->json('data.recommendations'))->firstWhere('kind', 'cash_flow_alert');

    expect($alert['priority'])->toBe('high')
        ->and($alert['proposed_payload']['first_negative_date'])->toBe(now()->addDays(2)->toDateString())
        ->and($alert['proposed_payload']['p10_balance'])->toBe(-2500);
});

test('the nightly job scores every tenant on its own and expires review items nobody looked at', function (): void {
    $other = ocrCompany('CH');
    aiSalesInvoice($this->tenant, $this->company['vendor'], 'RE-5', 7.7, now()->addDays(10)->toDateString());
    $stale = aiSuggestion($this->tenant, SuggestionKind::Contact, ['company_name' => 'Stale AG']);
    DB::table('ai_suggestions')->where('id', $stale->id)->update(['expires_at' => now()->subDay()]);

    NightlyPredictiveScoringJob::dispatchSync((string) $this->tenant->id);

    expect(DB::table('ai_suggestions')->where('tenant_id', $this->tenant->id)->where('kind', 'anomaly')->count())->toBe(1)
        ->and(DB::table('ai_suggestions')->where('tenant_id', $other['tenant']->id)->count())->toBe(0)
        ->and(DB::table('ai_suggestions')->where('id', $stale->id)->value('status'))->toBe('expired')
        ->and(DB::table('ai_audit_trails')->where('tenant_id', $this->tenant->id)->where('event_type', 'insights_generated')->exists())->toBeTrue();
});
