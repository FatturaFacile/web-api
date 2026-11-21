<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;
use Modules\AnalyticsDss\Infrastructure\Jobs\UpdateAnalyticalMartJob;
use Modules\AnalyticsDss\Infrastructure\Projection\MartProjector;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReconciler;
use Modules\AnalyticsDss\Infrastructure\Support\FxRateResolver;
use Modules\Projects\Events\ProjectTimesheetApprovedEvent;

require_once __DIR__.'/DssTestHelpers.php';

uses(RefreshDatabase::class);

test('a posted ledger entry is projected line by line with its analytical category and daily snapshot', function (): void {
    $company = dssCompany();
    dssRate('CHF', 'EUR', 0.9);
    dssTradingMonth($company);
    $tenantId = (string) $company['tenant']->id;

    $facts = DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->get();
    $byCategory = $facts->groupBy('category')->map->count();

    expect($facts)->toHaveCount(8)
        ->and($byCategory->sortKeys()->all())->toBe(['cash' => 2, 'cogs' => 1, 'opex' => 1, 'payable' => 1, 'receivable' => 2, 'revenue' => 1])
        ->and($facts->firstWhere('category', 'revenue')->legal_entity_id)->toBe($tenantId)
        ->and((float) $facts->firstWhere('category', 'revenue')->amount_functional)->toBe(-1000.0)
        ->and((float) $facts->firstWhere('category', 'revenue')->amount_chf)->toBe(-1000.0)
        // CHF to EUR at 0.90 on the posting date
        ->and((float) $facts->firstWhere('category', 'revenue')->amount_eur)->toBe(-900.0);

    $snapshot = DB::table('agg_executive_daily_snapshots')->where('tenant_id', $tenantId)->where('date_key', 20260310)->first();

    expect((float) $snapshot->revenue_chf)->toBe(1000.0)
        ->and((float) $snapshot->revenue_eur)->toBe(900.0)
        ->and((float) $snapshot->receivable_movement_chf)->toBe(1000.0)
        ->and((float) $snapshot->functional_checksum)->toBe(0.0)
        ->and((int) $snapshot->transaction_count)->toBe(2);

    $dimension = DB::table('dim_legal_entity')->where('tenant_id', $tenantId)->first();
    $date = DB::table('dim_date')->where('tenant_id', $tenantId)->where('date_key', 20260310)->first();

    expect($dimension->base_currency)->toBe('CHF')
        ->and(DB::table('dim_cost_center')->where('tenant_id', $tenantId)->where('cost_center_id', $company['center'])->value('name'))->toBe('Werkstatt')
        ->and(DB::table('dim_counterparty')->where('tenant_id', $tenantId)->where('contact_id', $company['customer'])->value('industry_code_system'))->toBe('NOGA')
        ->and((int) $date->quarter)->toBe(1)
        ->and((int) $date->week)->toBe(11)
        ->and((int) $date->is_weekend)->toBe(0);
});

test('projecting the same entry twice never doubles a figure, and an entry that is no longer posted leaves the mart', function (): void {
    $company = dssCompany();
    $tenantId = (string) $company['tenant']->id;
    $a = $company['accounts'];
    $entry = dssEntry($company['tenant'], '2026-03-10', [['account' => $a['receivable'], 'debit' => 300.0], ['account' => $a['revenue'], 'credit' => 300.0]]);

    UpdateAnalyticalMartJob::dispatchSync($tenantId, UpdateAnalyticalMartJob::SourceJournalEntry, $entry);
    UpdateAnalyticalMartJob::dispatchSync($tenantId, UpdateAnalyticalMartJob::SourceJournalEntry, $entry);

    expect(DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->count())->toBe(2)
        ->and((float) DB::table('agg_executive_daily_snapshots')->where('tenant_id', $tenantId)->sum('revenue_chf'))->toBe(300.0);

    DB::table('journal_entries')->where('id', $entry)->update(['status' => 'draft']);
    UpdateAnalyticalMartJob::dispatchSync($tenantId, UpdateAnalyticalMartJob::SourceJournalEntry, $entry);

    expect(DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->count())->toBe(0)
        ->and(DB::table('agg_executive_daily_snapshots')->where('tenant_id', $tenantId)->count())->toBe(0);
});

test('a reversed entry keeps counting and its reversal cancels it out', function (): void {
    $company = dssCompany();
    $a = $company['accounts'];
    $original = dssEntry($company['tenant'], '2026-03-10', [['account' => $a['receivable'], 'debit' => 100.0], ['account' => $a['revenue'], 'credit' => 100.0]], status: 'reversed');
    dssEntry($company['tenant'], '2026-03-11', [['account' => $a['receivable'], 'credit' => 100.0], ['account' => $a['revenue'], 'debit' => 100.0]]);

    $reader = app(MartReader::class);
    $filter = new DssFilter((string) $company['tenant']->id, asOf: '2026-06-30');

    expect($original)->not->toBe('')
        ->and($reader->movements($filter, '2026-01-01', '2026-06-30')['revenue'])->toBe(0.0)
        ->and(app(MartReconciler::class)->reconcile((string) $company['tenant']->id)['variance'])->toBe(0.0);
});

test('amounts are kept in the transaction, accounting and both reporting currencies', function (): void {
    $company = dssCompany(currency: 'EUR');
    dssRate('EUR', 'CHF', 0.95);
    $a = $company['accounts'];

    // A CHF 100 sale booked in an EUR ledger at 1.05 EUR per CHF.
    dssEntry($company['tenant'], '2026-03-10', [
        ['account' => $a['receivable'], 'debit' => 100.0, 'currency' => 'CHF', 'rate' => 1.05],
        ['account' => $a['revenue'], 'credit' => 100.0, 'currency' => 'CHF', 'rate' => 1.05],
    ]);

    $revenue = DB::table('fact_financial_transactions')->where('tenant_id', $company['tenant']->id)->where('category', 'revenue')->first();

    expect($revenue->transaction_currency)->toBe('CHF')
        ->and((float) $revenue->amount_transaction)->toBe(-100.0)
        ->and($revenue->functional_currency)->toBe('EUR')
        ->and((float) $revenue->amount_functional)->toBe(-105.0)
        ->and((float) $revenue->amount_eur)->toBe(-105.0)
        ->and((float) $revenue->amount_chf)->toBe(-99.75);

    $reader = app(MartReader::class);
    $filter = new DssFilter((string) $company['tenant']->id, currency: 'CHF', asOf: '2026-06-30');

    expect($reader->movements($filter, '2026-01-01', '2026-06-30')['revenue'])->toBe(99.75);
});

test('FX rates are the last one on or before the day: direct, inverse or crossed through EUR, own company before the global feed, never another company', function (): void {
    $company = dssCompany();
    $tenantId = (string) $company['tenant']->id;
    $fx = new FxRateResolver;

    dssRate('EUR', 'CHF', 0.9, '2026-01-01');
    dssRate('EUR', 'CHF', 0.95, '2026-02-01');
    dssRate('EUR', 'USD', 1.25, '2026-01-01');
    dssRate('EUR', 'CHF', 0.97, '2026-02-10', $tenantId);
    // The rate of another company, more recent still, must never be used.
    dssRate('EUR', 'CHF', 0.5, '2026-02-12', (string) dssCompany(actAs: false)['tenant']->id);

    expect($fx->rate($tenantId, 'EUR', 'CHF', '2026-01-15'))->toBe(0.9)
        ->and($fx->rate($tenantId, 'EUR', 'CHF', '2026-02-05'))->toBe(0.95)
        ->and($fx->rate($tenantId, 'EUR', 'CHF', '2026-02-15'))->toBe(0.97)
        ->and($fx->rate($tenantId, 'CHF', 'CHF', '2026-02-15'))->toBe(1.0)
        ->and(round((float) $fx->rate($tenantId, 'CHF', 'EUR', '2026-01-15'), 6))->toBe(round(1 / 0.9, 6))
        // CHF -> EUR -> USD
        ->and(round((float) (new FxRateResolver)->rate($tenantId, 'CHF', 'USD', '2026-01-15'), 6))->toBe(round(1 / 0.9 * 1.25, 6))
        ->and($fx->rate($tenantId, 'EUR', 'CHF', '2025-12-31'))->toBeNull()
        ->and($fx->convert($tenantId, 100.0, 'EUR', 'CHF', '2026-01-15'))->toBe(90.0);
});

test('the mart reconciles to the ledger with 0.00 variance and any drift is reported per account', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $tenantId = (string) $company['tenant']->id;
    $reconciler = app(MartReconciler::class);

    $clean = $reconciler->reconcile($tenantId);

    expect($clean['variance'])->toBe(0.0)
        ->and($clean['accounts_checked'])->toBe(6)
        ->and($clean['mismatches'])->toBe([])
        ->and($clean['snapshot_variance'])->toBe(0.0);

    DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->where('category', 'cogs')->update(['amount_functional' => 350]);
    $drift = $reconciler->reconcile($tenantId);

    expect($drift['variance'])->toBe(50.0)
        ->and($drift['mismatches'])->toHaveCount(1)
        ->and($drift['mismatches'][0]['account_id'])->toBe($company['accounts']['cogs'])
        ->and($drift['mismatches'][0]['variance'])->toBe(-50.0);

    // A line the projector never saw (posted straight in the ledger) is a variance too.
    dssEntry($company['tenant'], '2026-03-21', [['account' => $company['accounts']['cash'], 'debit' => 10.0], ['account' => $company['accounts']['revenue'], 'credit' => 10.0]], project: false);

    // 50 of drift on the cost of goods plus the 10 + 10 of the entry the mart never saw.
    expect($reconciler->reconcile($tenantId)['variance'])->toBe(70.0);
});

test('bi:consolidate-marts rebuilds a tenant from the ledger and its reconciliation ends at zero', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);
    $tenantId = (string) $company['tenant']->id;
    DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->delete();
    DB::table('agg_executive_daily_snapshots')->where('tenant_id', $tenantId)->delete();
    dssEntry($company['tenant'], '2026-03-22', [['account' => $company['accounts']['cash'], 'debit' => 10.0], ['account' => $company['accounts']['revenue'], 'credit' => 10.0]], project: false);

    $this->artisan('bi:consolidate-marts', ['--reconcile' => true, '--tenant' => $tenantId])->assertExitCode(0);

    expect(DB::table('fact_financial_transactions')->where('tenant_id', $tenantId)->count())->toBe(10)
        ->and(app(MartReconciler::class)->reconcile($tenantId)['variance'])->toBe(0.0);
});

test('bi:consolidate-marts only rebuilds the tenant it is given', function (): void {
    $first = dssCompany(actAs: false);
    $second = dssCompany(actAs: false);
    dssTradingMonth($first);
    dssTradingMonth($second);
    DB::table('fact_financial_transactions')->delete();
    DB::table('agg_executive_daily_snapshots')->delete();

    $this->artisan('bi:consolidate-marts', ['--tenant' => (string) $first['tenant']->id])->assertExitCode(0);

    expect(DB::table('fact_financial_transactions')->where('tenant_id', $first['tenant']->id)->count())->toBe(8)
        ->and(DB::table('fact_financial_transactions')->where('tenant_id', $second['tenant']->id)->count())->toBe(0);
});

test('every query of the mart is scoped to the tenant: another company never leaks in', function (): void {
    $first = dssCompany(actAs: false);
    $second = dssCompany(actAs: false);
    dssTradingMonth($first);
    dssEntry($second['tenant'], '2026-03-10', [['account' => $second['accounts']['receivable'], 'debit' => 5000.0], ['account' => $second['accounts']['revenue'], 'credit' => 5000.0]]);
    dssInvoice($second['tenant'], $second['customer'], 5000.0);

    $reader = app(MartReader::class);
    $filter = new DssFilter((string) $first['tenant']->id, asOf: '2026-06-30');

    expect($reader->movements($filter, '2026-01-01', '2026-06-30')['revenue'])->toBe(1000.0)
        ->and($reader->commercial($filter, '2026-01-01', '2026-06-30')['revenue'])->toBe(0.0)
        ->and($reader->legalEntities((string) $first['tenant']->id))->toHaveCount(1)
        ->and(app(MartReconciler::class)->reconcile((string) $first['tenant']->id)['accounts_checked'])->toBe(6);

    $costCenterFilter = new DssFilter((string) $first['tenant']->id, costCenterIds: [$second['center']], asOf: '2026-06-30');

    expect($reader->movements($costCenterFilter, '2026-01-01', '2026-06-30')['revenue'])->toBe(0.0);
});

test('an issued invoice becomes a commercial velocity fact with its settlement, channel and reporting amounts', function (): void {
    $company = dssCompany();
    dssRate('CHF', 'EUR', 0.9);
    $tenant = $company['tenant'];

    $paid = dssInvoice($tenant, $company['customer'], 1000.0, attributes: ['due_date' => '2026-03-31', 'payment_status' => 'paid', 'total_paid' => 1000, 'updated_at' => '2026-04-09 12:00:00', 'subscription_id' => (string) Str::uuid()]);
    $open = dssInvoice($tenant, $company['customer'], 500.0, '2026-03-12', ['due_date' => '2026-04-11']);
    $credit = dssInvoice($tenant, $company['customer'], 100.0, '2026-03-13', ['invoice_type' => 'credit_note']);
    $draft = (string) Str::uuid();
    DB::table('invoices')->insert(['id' => $draft, 'tenant_id' => $tenant->id, 'contact_id' => $company['customer'], 'invoice_number' => 'DRAFT-1', 'date' => '2026-03-14', 'created_at' => now(), 'updated_at' => now()]);
    UpdateAnalyticalMartJob::dispatchSync((string) $tenant->id, UpdateAnalyticalMartJob::SourceInvoice, $draft);

    $facts = DB::table('fact_commercial_velocity')->where('tenant_id', $tenant->id)->get()->keyBy('invoice_id');

    expect($facts)->toHaveCount(3)
        ->and($facts->has($draft))->toBeFalse()
        ->and($facts[$paid]->channel)->toBe('subscription')
        ->and((int) $facts[$paid]->days_to_pay)->toBe(30)
        ->and((float) $facts[$paid]->amount_open)->toBe(0.0)
        ->and((float) $facts[$paid]->amount_net_eur)->toBe(900.0)
        ->and((float) $facts[$open]->amount_open)->toBe(500.0)
        ->and((float) $facts[$open]->amount_open_eur)->toBe(450.0)
        ->and($facts[$open]->channel)->toBe('direct')
        ->and((float) $facts[$credit]->amount_net)->toBe(-100.0);

    $reader = app(MartReader::class);
    $filter = new DssFilter((string) $tenant->id, asOf: '2026-06-30');
    $commercial = $reader->commercial($filter, '2026-01-01', '2026-06-30');

    expect($commercial['revenue'])->toBe(1400.0)
        ->and($commercial['open_receivables'])->toBe(500.0)
        ->and($commercial['invoices'])->toBe(3)
        // 9 days late on the paid one, 80 days (April 11th to the end of June) on the open one; the credit note has no due date
        ->and($reader->paymentDelays($filter, '2026-01-01', '2026-06-30')[$company['customer']])->toBe(44.5);
});

test('approved timesheets feed utilization and FTE, other statuses are ignored', function (): void {
    $company = dssCompany();
    $tenant = $company['tenant'];

    dssTimesheet($tenant, 8.0, true);
    dssTimesheet($tenant, 2.0, false);
    dssTimesheet($tenant, 5.0, true, status: 'submitted');

    $reader = app(MartReader::class);
    $filter = new DssFilter((string) $tenant->id, asOf: '2026-06-30');
    $operations = $reader->operations($filter, '2026-01-01', '2026-06-30');
    $fte = $reader->fte($filter, '2026-01-01', '2026-06-30');

    expect(DB::table('fact_operational_efficiency')->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and($operations['billable_hours'])->toBe(8.0)
        ->and($operations['non_billable_hours'])->toBe(2.0)
        ->and($fte)->not->toBeNull()
        ->and($fte['fte'])->toBeGreaterThan(0.0)
        // 10 hours cost 500; one FTE over the 181 days of the period costs 500 / fte, annualized
        ->and($fte['annual_cost_per_fte'])->toBeGreaterThan(0.0);

    DB::table('project_timesheets')->where('tenant_id', $tenant->id)->where('status', 'submitted')->update(['status' => 'approved']);
    UpdateAnalyticalMartJob::dispatchSync((string) $tenant->id, UpdateAnalyticalMartJob::SourceTimesheet, (string) DB::table('project_timesheets')->where('hours_worked', 5)->value('id'));

    expect(DB::table('fact_operational_efficiency')->where('tenant_id', $tenant->id)->count())->toBe(3);
});

test('an outbound stock movement feeds inventory turnover, unit cost variance and the ABC class of the product', function (): void {
    $company = dssCompany();
    $tenant = $company['tenant'];
    $big = tfrProduct((string) $tenant->id, 'BIG-1', 8.0);
    $small = tfrProduct((string) $tenant->id, 'SMALL-1', 1.0);
    dssDeferForeignKeys();

    foreach ([[$big->id, 'out', 5, 10.0, 50.0], [$small->id, 'out', 1, 1.0, 1.0], [$big->id, 'in', 20, 8.0, 160.0]] as [$product, $type, $quantity, $unitCost, $value]) {
        $id = (string) Str::uuid();
        DB::table('stock_movements')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'product_id' => $product, 'type' => $type, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_valuation' => $value, 'executed_at' => '2026-03-18 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        UpdateAnalyticalMartJob::dispatchSync((string) $tenant->id, UpdateAnalyticalMartJob::SourceStockMovement, $id);
    }

    $fact = DB::table('fact_operational_efficiency')->where('tenant_id', $tenant->id)->where('product_id', $big->id)->first();

    expect(DB::table('fact_operational_efficiency')->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and((float) $fact->quantity_out)->toBe(5.0)
        ->and((float) $fact->value_out)->toBe(50.0)
        ->and((float) $fact->unit_cost)->toBe(10.0)
        // 10.00 paid against the 8.00 standard cost
        ->and((float) $fact->unit_cost_variance)->toBe(2.0)
        ->and(DB::table('dim_product_service')->where('tenant_id', $tenant->id)->where('product_id', $big->id)->value('abc_class'))->toBe('A')
        ->and(DB::table('dim_product_service')->where('tenant_id', $tenant->id)->where('product_id', $small->id)->value('abc_class'))->toBe('C')
        ->and(app(MartReader::class)->operations(new DssFilter((string) $tenant->id, asOf: '2026-06-30'), '2026-01-01', '2026-06-30')['value_out'])->toBe(51.0);
});

test('operational events enqueue the mart update instead of running it inline', function (): void {
    $company = dssCompany();
    Queue::fake();

    event(new ProjectTimesheetApprovedEvent((string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), 8.0, 400.0, 800.0, (string) Str::uuid(), (string) $company['tenant']->id, (string) $company['tenant']->id));

    Queue::assertPushed(UpdateAnalyticalMartJob::class, fn (UpdateAnalyticalMartJob $job): bool => $job->source === UpdateAnalyticalMartJob::SourceTimesheet && $job->tenantId === (string) $company['tenant']->id && $job->queue === 'analytics');
});

test('the projector rejects an unknown source and the job retries with backoff', function (): void {
    $job = new UpdateAnalyticalMartJob('t', 'nonsense', 'x');

    expect(fn () => $job->handle(app(MartProjector::class)))->toThrow(InvalidArgumentException::class)
        ->and($job->backoff())->toBe([10, 60, 300])
        ->and($job->tries)->toBe(3);
});

test('the tenant of a mart row is never null and the rows belong to existing tenants', function (): void {
    $company = dssCompany();
    dssTradingMonth($company);

    foreach (['fact_financial_transactions', 'agg_executive_daily_snapshots', 'dim_date', 'dim_legal_entity', 'dim_counterparty', 'dim_cost_center'] as $table) {
        expect(DB::table($table)->whereNull('tenant_id')->count())->toBe(0, $table);
    }

    expect(Tenant::query()->whereKey($company['tenant']->id)->exists())->toBeTrue();
});
