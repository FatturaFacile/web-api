<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Analytics\Models\ReportDefinition;
use Modules\HumanResources\Models\Employee;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Projects\Models\Project;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

/**
 * A sales invoice. `posted` sets posted_at the way the invoicing service does when it posts the document.
 */
function kpiInvoice(Tenant $tenant, string $date, string $net, array $overrides = [], bool $posted = true): Invoice
{
    $customer = $overrides['customer'] ?? Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente '.Str::random(4)]);
    unset($overrides['customer']);
    $invoice = (new Invoice)->forceFill([
        'contact_id' => $customer->id, 'invoice_type' => 'standard', 'invoice_number' => Str::random(8), 'date' => $date, 'due_date' => $date,
        'currency' => 'EUR', 'total_net' => $net, 'total_tax' => '0', 'total_gross' => $net, 'total_paid' => '0', 'payment_status' => 'unpaid', 'status' => $posted ? 'posted' : 'draft',
        ...$overrides,
    ]);
    $invoice->tenant_id = $tenant->id;

    if ($posted) {
        $invoice->posted_at = Carbon::parse($date);
    }

    $invoice->save();

    return $invoice;
}

function kpiPurchase(Tenant $tenant, string $date, string $net, bool $posted = true, array $overrides = []): PassiveInvoice
{
    $supplier = Contact::create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'company_name' => '=Fornitore '.Str::random(4)]);

    return PassiveInvoice::withoutEvents(function () use ($tenant, $supplier, $date, $net, $posted, $overrides): PassiveInvoice {
        $invoice = new PassiveInvoice([
            'supplier_id' => $supplier->id, 'invoice_number' => Str::random(6), 'date' => $date, 'due_date' => $date, 'total_amount' => $net, 'total_net' => $net,
            'total_tax' => 0, 'total_paid' => 0, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'document_hash' => Str::random(40),
            'gl_journal_entry_id' => $posted ? (string) Str::uuid() : null,
            ...$overrides,
        ]);
        $invoice->tenant_id = $tenant->id;
        $invoice->save();

        return $invoice;
    });
}

/**
 * One posted journal entry of the statutory ledger with a revenue and a cost-of-goods-sold line.
 */
function kpiLedger(Tenant $tenant, string $date, string $revenue, string $cogs): void
{
    $period = DB::table('fiscal_periods')->where('tenant_id', $tenant->id)->value('id');
    $account = function (string $code, string $type, string $normal) use ($tenant): string {
        $existing = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('account_code', $code)->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $id = (string) Str::uuid7();
        DB::table('chart_of_accounts')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'type' => $type === 'cogs' ? 'expense' : $type, 'account_code' => $code, 'name' => "Conto {$code}", 'account_type' => $type, 'normal_balance' => $normal, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };

    $entry = (string) Str::uuid7();
    DB::table('journal_entries')->insert([
        'id' => $entry, 'tenant_id' => $tenant->id, 'fiscal_period_id' => $period, 'entry_number' => 'JE-'.Str::random(6), 'posting_date' => $date, 'document_date' => $date,
        'description' => 'Test', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lines = [
        [$account('4000', 'revenue', 'credit'), '0', $revenue],
        [$account('5000', 'cogs', 'debit'), $cogs, '0'],
    ];

    foreach ($lines as $number => [$accountId, $debit, $credit]) {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entry, 'posting_date' => $date, 'line_number' => $number + 1, 'account_id' => $accountId,
            'description' => 'Test', 'debit_amount' => $debit, 'credit_amount' => $credit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = complianceTenant();
    $this->user = actingAsMember($this->tenant);
    openPeriod($this->tenant);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('sales count posted documents only and credit notes subtract', function (): void {
    kpiInvoice($this->tenant, '2027-03-05', '150.00');
    kpiInvoice($this->tenant, '2027-03-06', '-50.00', ['invoice_type' => 'credit_note']);
    kpiInvoice($this->tenant, '2027-03-07', '999.00', posted: false);
    kpiInvoice($this->tenant, '2027-03-08', '999.00', ['invoice_type' => 'proforma']);
    kpiInvoice($this->tenant, '2027-03-09', '999.00', ['status' => 'cancelled']);

    $kpi = $this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');

    expect($kpi['value'])->toBe('100.00')
        ->and($kpi['details']['document_count'])->toBe(2)
        ->and($kpi['available'])->toBeTrue();
});

test('a figure without posted data is null, not zero', function (): void {
    kpiInvoice($this->tenant, '2027-03-07', '999.00', posted: false);

    $report = $this->getJson('/api/v1/analytics/kpis?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');
    $byKey = collect($report['kpis'])->keyBy('key');

    expect($byKey['sales']['value'])->toBeNull()
        ->and($byKey['purchases']['value'])->toBeNull()
        ->and($byKey['margin']['value'])->toBeNull()
        ->and($byKey['receivables-aging']['value'])->toBeNull()
        ->and($byKey['payables-aging']['value'])->toBeNull()
        ->and($byKey['stock-value']['value'])->toBeNull()
        ->and($byKey['project-margin']['value'])->toBeNull()
        ->and($byKey['hr-headcount']['value'])->toBeNull()
        ->and($byKey)->toHaveCount(8);
});

test('the comparison period gives change and percentage, and no percentage against zero', function (): void {
    kpiInvoice($this->tenant, '2027-03-05', '150.00');
    kpiInvoice($this->tenant, '2027-02-05', '100.00');

    $kpi = $this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-31&compare=previous_period')->assertOk()->json('data');

    expect($kpi['comparison']['period'])->toBe(['from' => '2027-01-29', 'to' => '2027-02-28'])
        ->and($kpi['comparison']['value'])->toBe('100.00')
        ->and($kpi['change'])->toBe('50.00')
        ->and($kpi['change_percent'])->toBe('50.00');

    $year = $this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-31&compare=previous_year')->assertOk()->json('data');

    expect($year['comparison']['period'])->toBe(['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->and($year['comparison']['value'])->toBeNull()
        ->and($year['change'])->toBeNull()
        ->and($year['change_percent'])->toBeNull();
});

test('snapshot indicators are not compared with a past period', function (): void {
    $kpi = $this->getJson('/api/v1/analytics/kpis/receivables-aging?compare=previous_year')->assertOk()->json('data');

    expect($kpi['kind'])->toBe('snapshot')->and($kpi['comparison'])->toBeNull()->and($kpi['reason'])->toBe('snapshot_not_comparable');
});

test('purchases count only supplier invoices posted to the ledger', function (): void {
    kpiPurchase($this->tenant, '2027-03-05', '200.00');
    kpiPurchase($this->tenant, '2027-03-06', '30.00', overrides: ['document_type' => 'TD04']);
    kpiPurchase($this->tenant, '2027-03-07', '777.00', posted: false);

    $kpi = $this->getJson('/api/v1/analytics/kpis/purchases?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');

    expect($kpi['value'])->toBe('170.00')->and($kpi['details']['document_count'])->toBe(2);
});

test('foreign currency documents use their stored rate and are left out without one', function (): void {
    kpiInvoice($this->tenant, '2027-03-05', '100.00', ['currency' => 'CHF', 'exchange_rate' => '1.050000']);
    kpiInvoice($this->tenant, '2027-03-06', '500.00', ['currency' => 'USD', 'exchange_rate' => '0']);
    kpiPurchase($this->tenant, '2027-03-05', '100.00', overrides: ['currency' => 'CHF', 'exchange_rate' => '0.900000']);
    kpiPurchase($this->tenant, '2027-03-06', '500.00', overrides: ['currency' => 'USD', 'exchange_rate' => null]);

    $sales = $this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');
    $purchases = $this->getJson('/api/v1/analytics/kpis/purchases?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');

    expect($sales['value'])->toBe('105.00')->and($sales['details']['excluded_without_exchange_rate'])->toBe(1)
        ->and($purchases['value'])->toBe('90.00')->and($purchases['details']['excluded_without_exchange_rate'])->toBe(1);
});

test('margin reads the posted statutory ledger', function (): void {
    kpiLedger($this->tenant, '2027-03-10', '1000.00', '600.00');

    $kpi = $this->getJson('/api/v1/analytics/kpis/margin?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');

    expect($kpi['value'])->toBe('400.00')
        ->and($kpi['details'])->toMatchArray(['revenue' => '1000.00', 'cost_of_goods_sold' => '600.00', 'margin_percent' => '40.00']);
});

test('aging splits open posted items by days past due and ignores paid and unposted ones', function (): void {
    kpiInvoice($this->tenant, '2027-03-01', '100.00', ['due_date' => '2027-03-20']);
    kpiInvoice($this->tenant, '2027-02-01', '200.00', ['due_date' => '2027-03-01', 'total_paid' => '50.00']);
    kpiInvoice($this->tenant, '2026-10-01', '400.00', ['due_date' => '2026-11-01']);
    kpiInvoice($this->tenant, '2027-02-01', '900.00', ['due_date' => '2027-02-10', 'payment_status' => 'paid', 'total_paid' => '900.00']);
    kpiInvoice($this->tenant, '2027-02-01', '900.00', ['due_date' => '2027-02-10'], posted: false);
    kpiPurchase($this->tenant, '2027-03-01', '60.00', overrides: ['due_date' => '2027-02-01']);

    $receivables = $this->getJson('/api/v1/analytics/kpis/receivables-aging')->assertOk()->json('data');
    $payables = $this->getJson('/api/v1/analytics/kpis/payables-aging')->assertOk()->json('data');

    expect($receivables['value'])->toBe('650.00')
        ->and($receivables['details']['buckets'])->toBe(['not_due' => '100.00', 'days_1_30' => '150.00', 'days_31_60' => '0.00', 'days_61_90' => '0.00', 'over_90' => '400.00'])
        ->and($receivables['details']['overdue'])->toBe('550.00')
        ->and($payables['value'])->toBe('60.00')
        ->and($payables['details']['buckets']['days_31_60'])->toBe('60.00');
});

test('stock value multiplies the quants by the moving average cost', function (): void {
    $item = StockItem::factory()->create(['tenant_id' => $this->tenant->id, 'current_moving_average_cost' => '2.5000']);
    StockQuant::factory()->create(['tenant_id' => $this->tenant->id, 'stock_item_id' => $item->id, 'warehouse_bin_id' => WarehouseBin::factory()->create(['tenant_id' => $this->tenant->id])->id, 'quantity_on_hand' => '10.0000']);

    $kpi = $this->getJson('/api/v1/analytics/kpis/stock-value')->assertOk()->json('data');

    expect($kpi['value'])->toBe('25.00')->and($kpi['details']['item_count'])->toBe(1)->and($kpi['details']['ledger_inventory_balance'])->toBeNull();
});

test('headcount is rebuilt from hire and termination dates and compared', function (): void {
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2026-01-10', 'employment_status' => 'active', 'termination_date' => null]);
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2026-01-10', 'employment_status' => 'terminated', 'termination_date' => '2027-02-20']);
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2027-03-02', 'employment_status' => 'active', 'termination_date' => null]);
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2027-04-01', 'employment_status' => 'candidate', 'termination_date' => null]);

    $kpi = $this->getJson('/api/v1/analytics/kpis/hr-headcount?from=2027-03-01&to=2027-03-31&compare=previous_period')->assertOk()->json('data');

    expect($kpi['value'])->toBe('2')
        ->and($kpi['details']['hires_in_period'])->toBe(1)
        ->and($kpi['comparison']['value'])->toBe('1')
        ->and($kpi['change'])->toBe('1')
        ->and($kpi['change_percent'])->toBe('100.00');
});

test('project margin reports projects with revenue or cost only', function (): void {
    Project::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'contract_value' => '1000.00', 'currency' => 'EUR']);

    $kpi = $this->getJson('/api/v1/analytics/kpis/project-margin')->assertOk()->json('data');

    expect($kpi['value'])->toBeNull()->and($kpi['details']['project_count'])->toBe(0);
});

test('an indicator of a module the caller cannot read is unavailable, not an error', function (): void {
    actingAsMember($this->tenant, 'accountant');

    $headcount = $this->getJson('/api/v1/analytics/kpis/hr-headcount')->assertOk()->json('data');

    expect($headcount['available'])->toBeFalse()->and($headcount['reason'])->toBe('forbidden')->and($headcount['value'])->toBeNull();
    $this->get('/api/v1/analytics/kpis/hr-headcount/export')->assertForbidden();
});

test('kpi endpoints validate the period, the comparison and the indicator', function (): void {
    $this->getJson('/api/v1/analytics/kpis?from=2027-01-01&to=2028-12-31')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->getJson('/api/v1/analytics/kpis?from=2027-03-10&to=2027-03-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->getJson('/api/v1/analytics/kpis?compare=last_decade')->assertUnprocessable()->assertJsonValidationErrors(['compare']);
    $this->getJson('/api/v1/analytics/kpis?kpis[]=invented')->assertUnprocessable()->assertJsonValidationErrors(['kpis.0']);
    $this->getJson('/api/v1/analytics/kpis/invented')->assertNotFound();
});

test('analytics are isolated per tenant, closed to other roles and unauthenticated callers', function (): void {
    kpiInvoice($this->tenant, '2027-03-05', '150.00');

    actingAsMember(complianceTenant('Beta'));
    expect($this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-31')->json('data.value'))->toBeNull();

    actingAsMember($this->tenant, 'sales');
    $this->getJson('/api/v1/analytics/kpis')->assertForbidden();

    app('auth')->forgetGuards();
    $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/analytics/kpis')->assertUnauthorized();
});

test('the summary export is a neutralised csv and the detail export neutralises names', function (): void {
    kpiInvoice($this->tenant, '2027-03-05', '150.00', ['customer' => Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => '=HYPERLINK("http://evil")'])]);
    kpiPurchase($this->tenant, '2027-03-05', '80.00');

    $summary = $this->get('/api/v1/analytics/kpis-export?from=2027-03-01&to=2027-03-31&kpis[]=sales')->assertOk();
    expect($summary->headers->get('Content-Type'))->toContain('text/csv')
        ->and($summary->getContent())->toContain('"sales";"Net sales";"currency";"period";"150.00"');

    $detail = $this->get('/api/v1/analytics/kpis/sales/export?from=2027-03-01&to=2027-03-31')->assertOk();
    expect($detail->getContent())->toContain("\"'=HYPERLINK(")->and($detail->headers->get('X-Export-Truncated'))->toBe('false');

    $purchases = $this->get('/api/v1/analytics/kpis/purchases/export?from=2027-03-01&to=2027-03-31')->assertOk();
    expect($purchases->getContent())->toContain("\"'=Fornitore");
});

test('report definitions are saved per user, shared on request and validated', function (): void {
    $payload = ['name' => 'Board pack', 'kpis' => ['sales', 'margin'], 'period_preset' => 'month_to_date', 'compare' => 'previous_period'];

    $id = $this->postJson('/api/v1/analytics/report-definitions', $payload)->assertCreated()->assertJsonPath('data.is_owner', true)->json('data.id');
    $this->postJson('/api/v1/analytics/report-definitions', $payload)->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/analytics/report-definitions', [...$payload, 'name' => 'Bad', 'kpis' => ['invented']])->assertUnprocessable()->assertJsonValidationErrors(['kpis.0']);
    $this->postJson('/api/v1/analytics/report-definitions', [...$payload, 'name' => 'Custom', 'period_preset' => 'custom'])->assertUnprocessable()->assertJsonValidationErrors(['from_date', 'to_date']);

    $this->getJson("/api/v1/analytics/report-definitions/{$id}")->assertOk()->assertJsonPath('data.name', 'Board pack');

    // Another accountant sees nothing of a private definition, and everything of a shared one but cannot change it.
    actingAsMember($this->tenant, 'accountant');
    $this->getJson("/api/v1/analytics/report-definitions/{$id}")->assertNotFound();
    expect($this->getJson('/api/v1/analytics/report-definitions')->json('data'))->toBe([]);

    ReportDefinition::query()->whereKey($id)->update(['is_shared' => true]);
    $this->getJson("/api/v1/analytics/report-definitions/{$id}")->assertOk()->assertJsonPath('data.is_owner', false);
    $this->putJson("/api/v1/analytics/report-definitions/{$id}", $payload)->assertForbidden();
    $this->deleteJson("/api/v1/analytics/report-definitions/{$id}")->assertForbidden();

    // An administrator manages definitions of others.
    actingAsMember($this->tenant, 'admin');
    $this->putJson("/api/v1/analytics/report-definitions/{$id}", [...$payload, 'name' => 'Renamed', 'is_shared' => true])->assertOk()->assertJsonPath('data.name', 'Renamed');
    $this->deleteJson("/api/v1/analytics/report-definitions/{$id}")->assertOk();
    $this->getJson("/api/v1/analytics/report-definitions/{$id}")->assertNotFound();
});

test('a saved report runs on the calendar of today and exports to csv', function (): void {
    kpiInvoice($this->tenant, '2027-03-05', '150.00');
    $id = $this->postJson('/api/v1/analytics/report-definitions', ['name' => 'MTD', 'kpis' => ['sales'], 'period_preset' => 'month_to_date', 'compare' => 'previous_period'])->json('data.id');

    $run = $this->getJson("/api/v1/analytics/report-definitions/{$id}/run")->assertOk()->json('data');

    expect($run['period'])->toBe(['from' => '2027-03-01', 'to' => '2027-03-15'])
        ->and($run['kpis'][0]['value'])->toBe('150.00');

    $this->get("/api/v1/analytics/report-definitions/{$id}/export")->assertOk()->assertHeader('Content-Disposition', "attachment; filename=\"report-{$id}.csv\"");

    $tooLong = $this->postJson('/api/v1/analytics/report-definitions', ['name' => 'Long', 'kpis' => ['sales'], 'period_preset' => 'custom', 'from_date' => '2025-01-01', 'to_date' => '2027-01-01'])->assertCreated()->json('data.id');
    $this->getJson("/api/v1/analytics/report-definitions/{$tooLong}/run")->assertUnprocessable();
});

test('a read-only role reads and runs reports but cannot save them', function (): void {
    $definition = ReportDefinition::factory()->shared()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->getJson("/api/v1/analytics/report-definitions/{$definition->id}/run")->assertOk();
    $this->postJson('/api/v1/analytics/report-definitions', ['name' => 'Nope', 'kpis' => ['sales'], 'period_preset' => 'month_to_date'])->assertForbidden();
});

test('a definition of another tenant is not found', function (): void {
    $other = complianceTenant('Beta');
    $foreign = ReportDefinition::factory()->shared()->create(['tenant_id' => $other->id]);

    $this->getJson("/api/v1/analytics/report-definitions/{$foreign->id}")->assertNotFound();
    $this->getJson('/api/v1/analytics/report-definitions/not-a-uuid')->assertNotFound();
});
