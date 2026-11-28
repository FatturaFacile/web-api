<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\AssetManagement\Events\AssetDepreciationPostedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetRegisteredEvent;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\TimeTravel\Application\Queries\ReconstructStateAtBiTemporalCoordinateHandler;
use Modules\TimeTravel\Application\Queries\ReconstructStateAtBiTemporalCoordinateQuery;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Listeners\FixedAssetEventCapture;
use Modules\TimeTravel\Listeners\GeneralLedgerEventCapture;
use Modules\TimeTravel\Listeners\InventoryEventCapture;
use Modules\TimeTravel\Listeners\OpenItemsEventCapture;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\Treasury\Events\CollectionReceivedEvent;

require_once __DIR__.'/TimeTravelSupport.php';

beforeEach(function (): void {
    ttMember($this);
    $this->tid = (string) $this->tenant->id;
});

function ttCapturedState(object $test, QueryDomain $domain, array $filters = []): array
{
    return app(ReconstructStateAtBiTemporalCoordinateHandler::class)->handle(new ReconstructStateAtBiTemporalCoordinateQuery(
        (string) $test->tenant->id,
        $domain,
        BiTemporalCoordinate::of('2099-12-31', '2099-12-31'),
        $filters,
    ));
}

it('appends a posted journal entry of the ledger with its lines, atomically with the posting', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    app(OpenFiscalYearAction::class)->execute($this->tid, 2026);

    $account = fn (string $code, string $type, string $normal) => ChartOfAccount::factory()->create([
        'tenant_id' => $this->tid, 'company_id' => null, 'account_code' => $code, 'account_type' => $type, 'account_class' => $type, 'normal_balance' => $normal, 'is_active' => true, 'allows_manual_posting' => true,
    ]);
    $cash = $account('1000', 'asset', 'debit');
    $sales = $account('4000', 'revenue', 'credit');
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->tid)->whereDate('start_date', '<=', '2026-06-15')->whereDate('end_date', '>=', '2026-06-15')->firstOrFail();

    $entry = app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $this->tid, 'fiscal_period_id' => $period->id, 'posting_date' => '2026-06-15', 'description' => 'Cash sale', 'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => '122.00', 'credit_amount' => '0'],
            ['account_id' => $sales->id, 'debit_amount' => '0', 'credit_amount' => '122.00'],
        ],
    ]);

    $event = BiTemporalEvent::query()->where('aggregate_id', $entry->id)->firstOrFail();

    expect($event->event_type)->toBe('JournalEntryPosted')
        ->and($event->stream_id)->toBe('general_ledger')
        ->and(substr((string) $event->valid_at, 0, 10))->toBe('2026-06-15')
        ->and(substr((string) $event->recorded_at, 0, 10))->toBe('2026-06-15')
        ->and(collect($event->payload['lines'])->pluck('account_id')->sort()->values()->all())->toBe(collect([$cash->id, $sales->id])->sort()->values()->all())
        ->and($event->payload['entry_number'])->toBe((string) $entry->entry_number)
        ->and($event->metadata['source_event'])->toBe('general_ledger.journal.posted')
        ->and($event->cryptographic_chain_hash)->toMatch('/^[0-9a-f]{64}$/');

    $state = ttCapturedState($this, QueryDomain::TrialBalance);

    expect($state['totals'])->toBe(['debit' => '122.00', 'credit' => '122.00', 'balance' => '0.00'])
        ->and(collect($state['rows'])->pluck('account_code')->sort()->values()->all())->toBe(['1000', '4000']);

    // The same entry announced again is recorded once.
    app(GeneralLedgerEventCapture::class)->onJournalPosted(new JournalPostedEvent((string) $entry->id, (string) $entry->entry_number, (string) $entry->fiscal_period_id, 122.0, 'statutory', 'standard', '2026-06-15', $this->tid, $this->tid));

    expect(BiTemporalEvent::query()->where('aggregate_id', $entry->id)->count())->toBe(1);
});

it('rolls the event back with the posting when the transaction fails', function (): void {
    try {
        DB::transaction(function (): void {
            ttRecord($this, $this->tid, AggregateType::GeneralLedger, 'je-x', 'JournalEntryPosted', '2026-06-15', '2026-06-15 10:00:00', ['lines' => []]);

            throw new RuntimeException('posting failed');
        });
    } catch (RuntimeException) {
    }

    expect(BiTemporalEvent::query()->count())->toBe(0);
});

it('captures the receivables: invoice, collection, credit note, manual payment and credit limit', function (): void {
    $tid = $this->tid;
    $capture = app(OpenItemsEventCapture::class);

    $capture->onInvoicePosted(new InvoicePostedEvent('inv-1', 'FT-1', 'cust-1', '2026-01-10', '2026-02-10', 'EUR', 1.0, 1000.0, 220.0, 1220.0, [], [], null, $tid, $tid));
    $capture->onCollectionReceived(new CollectionReceivedEvent('col-1', 'cust-1', 'inv-1', 500.0, '2026-02-01', $tid, $tid));
    // The invoice is later settled without a treasury collection: only the part nobody reconciled yet is added.
    $capture->onInvoicePaid(new InvoicePaidEvent('inv-1', 'FT-1', 'cust-1', 'EUR', 1220.0, 1220.0, '2026-03-01', $tid, $tid));
    $capture->onInvoicePosted(new InvoicePostedEvent('inv-2', 'FT-2', 'cust-1', '2026-01-20', '2026-02-20', 'EUR', 1.0, 100.0, 22.0, 122.0, [], [], null, $tid, $tid));
    $capture->onCreditNoteIssued(new CreditNoteIssuedEvent('cn-1', 'NC-1', 'inv-2', 'cust-1', 22.0, [], $tid, $tid));
    $capture->onCreditLimitUpdated(new CustomerCreditLimitUpdatedEvent('prof-1', 'cust-1', 1000.0, 5000.0, 'someone', $tid, $tid));
    // A duplicate announcement of an invoice is recorded once.
    $capture->onInvoicePosted(new InvoicePostedEvent('inv-1', 'FT-1', 'cust-1', '2026-01-10', '2026-02-10', 'EUR', 1.0, 1000.0, 220.0, 1220.0, [], [], null, $tid, $tid));

    $types = BiTemporalEvent::query()->where('stream_id', 'open_items')->orderBy('sequence_number')->pluck('event_type')->all();
    $paid = BiTemporalEvent::query()->where('event_type', 'PaymentReconciled')->orderBy('sequence_number')->get()->map(fn ($event) => $event->payload['paid_amount'])->all();

    expect($types)->toBe(['InvoiceIssued', 'PaymentReconciled', 'PaymentReconciled', 'InvoiceIssued', 'CreditNoteIssued', 'CreditLimitAdjusted'])
        ->and($paid)->toBe(['500.000000', '720.000000']);

    $aging = ttCapturedState($this, QueryDomain::Aging);

    // inv-1 is fully paid; inv-2 is open for 122 - 22 credited.
    expect(collect($aging['rows'])->pluck('open_amount', 'document_id')->all())->toBe(['inv-2' => '100.00'])
        ->and($aging['totals']['credit_limits'])->toBe(['cust-1' => '5000.00']);
});

it('captures the fixed asset lifecycle', function (): void {
    $tid = $this->tid;
    $capture = app(FixedAssetEventCapture::class);

    $capture->onRegistered(new AssetRegisteredEvent('as-1', 'FA-1', 'Press', 'cat-1', 12000.0, 'purchase', $tid, $tid));
    $capture->onDepreciationPosted(new AssetDepreciationPostedEvent('sch-1', 'as-1', 'je-none', '2026-01', 100.0, 11900.0, $tid, $tid));
    // The same schedule posted again never depreciates twice.
    $capture->onDepreciationPosted(new AssetDepreciationPostedEvent('sch-1', 'as-1', 'je-none', '2026-01', 100.0, 11900.0, $tid, $tid));
    $capture->onDisposed(new AssetDisposedEvent('disp-1', 'as-1', 'sale', 12000.0, 100.0, 11900.0, 9000.0, -2900.0, '2026-06-30', $tid, $tid));

    expect(BiTemporalEvent::query()->where('stream_id', 'fixed_assets')->orderBy('sequence_number')->pluck('event_type')->all())
        ->toBe(['AssetAcquired', 'AssetDepreciated', 'AssetDisposed']);

    $assets = ttCapturedState($this, QueryDomain::FixedAssets);

    expect($assets['rows'][0])->toMatchArray(['asset_number' => 'FA-1', 'status' => 'disposed', 'carrying_amount' => '0.00', 'accumulated_depreciation' => '100.00']);
});

it('ignores a stock movement that no longer exists and everything while the capture is switched off', function (): void {
    app(InventoryEventCapture::class)->onStockTransferred(new StockTransferredEvent('missing-movement', 'item-1', null, 'bin-1', 5.0, 'receipt', 50.0, $this->tid, $this->tid));

    expect(BiTemporalEvent::query()->count())->toBe(0);

    config(['time_travel.enabled' => false]);
    app(OpenItemsEventCapture::class)->onInvoicePosted(new InvoicePostedEvent('inv-1', 'FT-1', 'cust-1', '2026-01-10', '2026-02-10', 'EUR', 1.0, 1000.0, 220.0, 1220.0, [], [], null, $this->tid, $this->tid));

    expect(BiTemporalEvent::query()->count())->toBe(0);
});

it('registers every capture listener on the domain events', function (): void {
    foreach ([JournalPostedEvent::class, InvoicePostedEvent::class, CollectionReceivedEvent::class, StockTransferredEvent::class, AssetRegisteredEvent::class, AssetDisposedEvent::class] as $event) {
        expect(Event::hasListeners($event))->toBeTrue($event);
    }
});

it('backfills the history once and seals and verifies it with the artisan command', function (): void {
    $tid = $this->tid;

    $this->artisan('bitemporal:backfill-and-snapshot', ['--tenant' => $tid, '--verify' => true])->assertSuccessful();

    // A first run on an empty tenant creates one checkpoint per stream and nothing else.
    expect(DB::table('es_materialized_checkpoints')->where('tenant_id', $tid)->count())->toBe(4)
        ->and(BiTemporalEvent::query()->count())->toBe(0);

    ttJournal($this, $tid, 'je-1', '2026-01-02', '2026-01-02 09:00:00', [['cash', '1.00', '0'], ['sales', '0', '1.00']]);

    $this->artisan('bitemporal:backfill-and-snapshot', ['--tenant' => $tid, '--skip-backfill' => true, '--verify' => true])->assertSuccessful();

    expect(DB::table('es_merkle_audit_anchors')->where('tenant_id', $tid)->count())->toBeGreaterThan(0);

    // A tampered chain makes the command fail.
    DB::table('es_bitemporal_event_streams')->where('aggregate_id', 'je-1')->update(['event_type' => 'Changed']);

    $this->artisan('bitemporal:backfill-and-snapshot', ['--tenant' => $tid, '--skip-backfill' => true, '--skip-checkpoints' => true, '--skip-seal' => true, '--verify' => true])->assertFailed();
});
