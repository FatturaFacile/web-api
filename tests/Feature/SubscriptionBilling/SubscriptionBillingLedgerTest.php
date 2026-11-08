<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * End to end: the engine issues real invoices through the invoicing services (gapless numbers, ledger posting on the
 * deferred revenue account), bills each period once, and the monthly recognition moves deferred revenue to revenue
 * through the General Ledger posting service.
 */
beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->user = SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant);
    $this->plan = SubscriptionScenario::plan($this->tenant, ['base_price' => '120.0000']);
});

function ledgerContract(array $overrides = []): string
{
    return test()->postJson('/api/v1/sub/contracts', array_merge([
        'customer_id' => test()->customer->id,
        'plan_id' => test()->plan->id,
        'start_date' => '2026-01-15',
        'contract_term_months' => 12,
    ], $overrides))->assertCreated()->json('data.id');
}

test('billing catches up the due periods with issued invoices numbered by the invoicing sequence', function (): void {
    $id = ledgerContract();

    $response = $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    $invoices = SalesInvoice::query()->whereIn('id', InvoiceSchedule::query()->where('subscription_id', $id)->pluck('invoice_id'))->orderBy('date')->get();
    expect($invoices->pluck('invoice_number')->all())->toBe(['INV-2026-0001', 'INV-2026-0002', 'INV-2026-0003'])
        ->and($invoices->pluck('status')->unique()->all())->toBe(['posted'])
        ->and($invoices->map(fn ($invoice) => CarbonImmutable::parse($invoice->getRawOriginal('date'))->toDateString())->all())->toBe(['2026-01-15', '2026-02-15', '2026-03-15'])
        ->and((string) $invoices->first()->total_gross)->toBe('146.40')
        ->and(Subscription::query()->find($id)->current_period_start->toDateString())->toBe('2026-04-15');
});

test('running the billing twice, or as a whole run and per contract, gives one invoice per period', function (): void {
    $id = ledgerContract();

    $this->postJson('/api/v1/subscriptions/billing-runs')->assertOk()->assertJsonPath('data.successful_invoices_count', 3);
    $this->postJson('/api/v1/subscriptions/billing-runs')->assertOk()->assertJsonPath('data.successful_invoices_count', 0);
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk()->assertJsonCount(0, 'data');
    $this->artisan('subscriptions:process')->assertSuccessful();

    expect(InvoiceSchedule::query()->where('subscription_id', $id)->where('billing_kind', 'recurring')->where('status', 'invoiced')->count())->toBe(3)
        ->and(SalesInvoice::query()->count())->toBe(3);
});

test('the database refuses a second invoiced claim on the same period', function (): void {
    $id = ledgerContract();
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    $schedule = InvoiceSchedule::query()->where('subscription_id', $id)->firstOrFail();

    expect(fn () => InvoiceSchedule::create([
        'tenant_id' => $this->tenant->id, 'subscription_id' => $id, 'billing_kind' => 'recurring', 'status' => 'processing',
        'scheduled_billing_date' => '2026-01-15', 'service_period_start' => $schedule->service_period_start->toDateString(), 'service_period_end' => '2026-02-14',
    ]))->toThrow(QueryException::class);
});

test('invoices credit the deferred revenue and the monthly recognition moves it to revenue once', function (): void {
    $id = ledgerContract();
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();

    // 3 x 120 net on the contract liability, 3 x 146.40 receivable.
    expect(SubscriptionScenario::balance($this->tenant, '2300'))->toBe('-360.00')
        ->and(SubscriptionScenario::balance($this->tenant, '1100'))->toBe('439.20')
        ->and(SubscriptionScenario::balance($this->tenant, '4000'))->toBe('0.00');

    $first = $this->postJson('/api/v1/subscriptions/revenue-recognition/recognize', ['period_date' => '2026-02-28'])->assertOk();
    expect($first->json('data.months'))->toBe(['2026-01-31', '2026-02-28'])
        ->and($first->json('data.errors'))->toBe([]);

    // Jan 15 - Feb 14 split by days (17/31 in January), then Feb 15 - Mar 14 (14/28 in February).
    $recognized = SubscriptionScenario::balance($this->tenant, '4000');
    expect($recognized)->toBe(bcmul((string) $first->json('data.total_amount'), '-1', 2))
        ->and(bcadd(SubscriptionScenario::balance($this->tenant, '2300'), $recognized, 2))->toBe('-360.00');

    $again = $this->postJson('/api/v1/subscriptions/revenue-recognition/recognize', ['period_date' => '2026-02-28'])->assertOk();
    expect($again->json('data.entries_count'))->toBe(0)
        ->and(JournalEntry::query()->where('source_document_type', 'subscription_revenue')->count())->toBe(2);

    $this->travelTo('2026-06-01 09:00:00');
    $this->postJson('/api/v1/subscriptions/revenue-recognition/recognize')->assertOk();
    // Everything billed (Jan 15 - Apr 14) is recognized by the end of April.
    expect(SubscriptionScenario::balance($this->tenant, '2300'))->toBe('0.00')
        ->and(SubscriptionScenario::balance($this->tenant, '4000'))->toBe('-360.00')
        ->and(RevenueSchedule::query()->where('is_recognized', false)->count())->toBe(0);
});

test('a downgrade issues a credit note, releases its deferred revenue and keeps the books balanced', function (): void {
    $id = ledgerContract(['start_date' => '2026-03-01']);
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    $cheaper = SubscriptionScenario::plan($this->tenant, ['name' => 'Cloud Mini', 'base_price' => '60.0000']);

    $this->postJson("/api/v1/sub/contracts/{$id}/downgrade", ['new_plan_id' => $cheaper->id, 'effective_date' => '2026-03-17'])->assertOk()
        ->assertJsonPath('data.mrr', '60.00');

    // 15 days of 31 left: (60 - 120) x 15 / 31 = -29.03.
    $credit = InvoiceSchedule::query()->where('subscription_id', $id)->where('billing_kind', 'credit')->firstOrFail();
    $creditNote = SalesInvoice::query()->findOrFail($credit->credit_note_id);
    expect($creditNote->invoice_type)->toBe('credit_note')
        ->and((string) $creditNote->total_net)->toBe('29.03')
        ->and((string) $credit->net_amount)->toBe('-29.03')
        ->and(JournalEntry::query()->where('source_document_type', 'subscription_credit_release')->where('source_document_id', $creditNote->id)->exists())->toBeTrue();

    $this->travelTo('2026-04-30 09:00:00');
    $this->postJson('/api/v1/subscriptions/revenue-recognition/recognize', ['period_date' => '2026-03-31'])->assertOk();
    // March earned: 120 - 29.03; the contra-revenue of the credit note is offset by the release.
    expect(SubscriptionScenario::balance($this->tenant, '4000'))->toBe('-90.97')
        ->and(SubscriptionScenario::balance($this->tenant, '4090'))->toBe('0.00')
        ->and(SubscriptionScenario::balance($this->tenant, '2300'))->toBe('0.00');
});

test('an upgrade invoices the proration for the rest of the billed period', function (): void {
    $id = ledgerContract(['start_date' => '2026-03-01']);
    $this->postJson("/api/v1/sub/contracts/{$id}/bill")->assertOk();
    $pro = SubscriptionScenario::plan($this->tenant, ['name' => 'Cloud Pro', 'base_price' => '182.0000']);

    $this->getJson("/api/v1/sub/contracts/{$id}/proration-preview?new_plan_id={$pro->id}&effective_date=2026-03-17")->assertOk()
        ->assertJsonPath('data.net_proration', '30.00')
        ->assertJsonPath('data.remaining_days', 15);

    $this->postJson("/api/v1/sub/contracts/{$id}/upgrade", ['new_plan_id' => $pro->id, 'effective_date' => '2026-03-17'])->assertOk();

    $proration = InvoiceSchedule::query()->where('subscription_id', $id)->where('billing_kind', 'proration')->firstOrFail();
    $invoice = SalesInvoice::query()->findOrFail($proration->invoice_id);
    expect((string) $invoice->total_net)->toBe('30.00')
        ->and($invoice->status)->toBe('posted')
        ->and($invoice->invoice_number)->toBe('INV-2026-0002');
});

test('a billing failure rolls the invoice and its number back and the next run retries', function (): void {
    $id = ledgerContract(['start_date' => '2026-03-01']);
    FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereDate('start_date', '<=', '2026-03-01')->whereDate('end_date', '>=', '2026-03-01')->update(['status' => 'closed']);

    $this->postJson('/api/v1/subscriptions/billing-runs')->assertOk()->assertJsonPath('data.status', 'failed');
    expect(SalesInvoice::query()->count())->toBe(0)
        ->and(InvoiceSchedule::query()->where('subscription_id', $id)->value('status'))->toBe('failed');

    FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['status' => 'open']);
    $this->postJson('/api/v1/subscriptions/billing-runs')->assertOk()->assertJsonPath('data.successful_invoices_count', 1);
    expect(SalesInvoice::query()->value('invoice_number'))->toBe('INV-2026-0001');
});
