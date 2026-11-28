<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TimeTravel\Application\Commands\RunWhatIfValuationReplayCommand;
use Modules\TimeTravel\Application\Commands\RunWhatIfValuationReplayHandler;
use Modules\TimeTravel\Application\Queries\CompareBiTemporalCutOffDiffHandler;
use Modules\TimeTravel\Application\Queries\CompareBiTemporalCutOffDiffQuery;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Domain\Enums\ValuationMethod;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Models\WhatIfSimulationRun;

require_once __DIR__.'/TimeTravelSupport.php';

beforeEach(function (): void {
    ttMember($this);
    $this->tid = (string) $this->tenant->id;
});

function ttDiff(object $test, QueryDomain $domain, array $filters = []): array
{
    return app(CompareBiTemporalCutOffDiffHandler::class)->handle(new CompareBiTemporalCutOffDiffQuery(
        (string) $test->tenant->id,
        $domain,
        CarbonImmutable::parse('2025-12-31 23:59:59.999999', 'UTC'),
        CarbonImmutable::parse('2026-01-15 23:59:59', 'UTC'),
        CarbonImmutable::parse('2026-03-31 23:59:59', 'UTC'),
        $filters,
    ))->toArray();
}

it('isolates the backdated entries between two moments of knowledge and explains every delta', function (): void {
    $author = User::create(['name' => 'Bianca Rossi', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);

    ttJournal($this, $this->tid, 'je-1', '2025-12-10', '2025-12-10 09:00:00', [['cash', '1000.00', '0'], ['capital', '0', '1000.00']]);
    // Dated 28/12/2025 but recorded on 05/03/2026: backdated, inside the window.
    ttJournal($this, $this->tid, 'je-2', '2025-12-28', '2026-03-05 09:00:00', [['cash', '250.00', '0'], ['sales', '0', '250.00']], (string) $author->id);
    // Recorded in the window but dated 2026: not part of the 2025 cut-off.
    ttJournal($this, $this->tid, 'je-3', '2026-02-01', '2026-02-01 09:00:00', [['cash', '40.00', '0'], ['sales', '0', '40.00']]);
    // Recorded after the window.
    ttJournal($this, $this->tid, 'je-4', '2025-12-29', '2026-05-01 09:00:00', [['cash', '9.00', '0'], ['sales', '0', '9.00']]);

    $diff = ttDiff($this, QueryDomain::TrialBalance);

    expect(collect($diff['backdated_entries'])->pluck('aggregate_id')->all())->toBe(['je-2'])
        ->and($diff['backdated_entries'][0])->toMatchArray([
            'event_type' => 'JournalEntryPosted',
            'actor_user_id' => (string) $author->id,
            'actor_name' => 'Bianca Rossi',
            'source_document' => 'manual:je-2',
            'amount_delta' => '250.00',
        ])
        ->and($diff['backdated_entries'][0]['cryptographic_chain_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($diff['backdated_entries'][0]['payload']['lines'])->toHaveCount(2)
        ->and(CarbonImmutable::parse($diff['backdated_entries'][0]['recorded_at'])->toDateString())->toBe('2026-03-05')
        ->and(CarbonImmutable::parse($diff['backdated_entries'][0]['valid_at'])->toDateString())->toBe('2025-12-28');

    $rows = collect($diff['rows'])->keyBy('key');

    expect($rows['cash'])->toMatchArray(['status' => 'backdated', 'before' => '1000.00', 'after' => '1250.00', 'delta' => '250.00'])
        ->and($rows['sales'])->toMatchArray(['status' => 'backdated', 'before' => '0.00', 'after' => '-250.00', 'delta' => '-250.00'])
        ->and($rows['capital'])->toMatchArray(['status' => 'unchanged', 'before' => '-1000.00', 'after' => '-1000.00', 'delta' => '0.00'])
        ->and($diff['totals'])->toBe(['before' => '1000.00', 'after' => '1250.00', 'delta' => '250.00']);

    // Changed rows come first.
    expect($diff['rows'][0]['status'])->toBe('backdated')->and(end($diff['rows'])['status'])->toBe('unchanged');
});

it('returns no backdated entry and no delta when nothing was recorded late', function (): void {
    ttJournal($this, $this->tid, 'je-1', '2025-12-10', '2025-12-10 09:00:00', [['cash', '1000.00', '0'], ['capital', '0', '1000.00']]);

    $diff = ttDiff($this, QueryDomain::TrialBalance);

    expect($diff['backdated_entries'])->toBe([])
        ->and(collect($diff['rows'])->pluck('status')->unique()->all())->toBe(['unchanged'])
        ->and($diff['totals']['delta'])->toBe('0.00');
});

it('compares the aging, the inventory and the account ledger the same way', function (): void {
    ttRecord($this, $this->tid, AggregateType::OpenItems, 'inv-1', 'InvoiceIssued', '2025-12-01', '2025-12-01 10:00:00', ['document_id' => 'inv-1', 'document_number' => 'FT-1', 'partner_id' => 'c', 'partner_type' => 'customer', 'issue_date' => '2025-12-01', 'due_date' => '2025-12-31', 'gross' => '800.00']);
    // A payment dated in 2025 and entered in March.
    ttRecord($this, $this->tid, AggregateType::OpenItems, 'inv-1', 'PaymentReconciled', '2025-12-20', '2026-03-02 10:00:00', ['document_id' => 'inv-1', 'partner_type' => 'customer', 'paid_amount' => '300.00']);
    ttStock($this, $this->tid, 'mv-1', '2025-12-05 08:00:00', '2025-12-05 08:00:00', 'item-a', 'wh-1', '10', '100.00');
    ttStock($this, $this->tid, 'mv-2', '2025-12-30 08:00:00', '2026-02-20 08:00:00', 'item-a', 'wh-1', '5', '75.00');
    ttJournal($this, $this->tid, 'je-1', '2025-12-10', '2025-12-10 09:00:00', [['cash', '100.00', '0'], ['sales', '0', '100.00']]);
    ttJournal($this, $this->tid, 'je-2', '2025-12-12', '2026-03-01 09:00:00', [['cash', '0', '20.00'], ['rent', '20.00', '0']]);

    $aging = ttDiff($this, QueryDomain::Aging);
    $inventory = ttDiff($this, QueryDomain::Inventory);
    $ledger = ttDiff($this, QueryDomain::AccountLedger, ['account_id' => 'cash']);

    expect($aging['rows'][0])->toMatchArray(['key' => 'inv-1', 'before' => '800.00', 'after' => '500.00', 'delta' => '-300.00', 'status' => 'backdated'])
        ->and($aging['backdated_entries'][0]['amount_delta'])->toBe('-300.00')
        ->and($inventory['rows'][0])->toMatchArray(['before' => '100.00', 'after' => '175.00', 'delta' => '75.00', 'status' => 'backdated'])
        ->and($inventory['backdated_entries'][0]['source_document'])->toBe('mv-2')
        ->and(collect($ledger['rows'])->pluck('status', 'label')->all())->toBe(['JE-je-2' => 'backdated', 'JE-je-1' => 'unchanged'])
        ->and($ledger['backdated_entries'][0]['amount_delta'])->toBe('-20.00');
});

it('replays the inventory under WAC, FIFO and LIFO and compares each with the official valuation', function (): void {
    // 10 @ 10, 10 @ 20, then an issue of 10 booked by the books at the average (150.00).
    ttStock($this, $this->tid, 'mv-1', '2026-01-02 08:00:00', '2026-01-02 08:00:00', 'item-a', 'wh-1', '10', '100.00', '10');
    ttStock($this, $this->tid, 'mv-2', '2026-01-03 08:00:00', '2026-01-03 08:00:00', 'item-a', 'wh-1', '10', '200.00', '20');
    ttStock($this, $this->tid, 'mv-3', '2026-01-04 08:00:00', '2026-01-04 08:00:00', 'item-a', 'wh-1', '-10', '-150.00', '15');
    // Another warehouse and a movement known too late for the coordinate.
    ttStock($this, $this->tid, 'mv-4', '2026-01-05 08:00:00', '2026-01-05 08:00:00', 'item-b', 'wh-2', '4', '40.00', '10');
    ttStock($this, $this->tid, 'mv-5', '2026-01-06 08:00:00', '2026-06-01 08:00:00', 'item-a', 'wh-1', '-5', '-75.00', '15');

    $run = function (ValuationMethod $method, array $scope = []) {
        return app(RunWhatIfValuationReplayHandler::class)->handle(new RunWhatIfValuationReplayCommand(
            (string) $this->tenant->id,
            BiTemporalCoordinate::of('2026-01-31', '2026-02-01'),
            $method,
            $scope['warehouse'] ?? null,
            $scope['item'] ?? null,
            null,
            (string) $this->user->id,
        ));
    };

    $before = BiTemporalEvent::withoutGlobalScopes()->count();
    $movementsBefore = DB::table('stock_movements')->count();

    $wac = $run(ValuationMethod::Wac, ['item' => 'item-a']);
    $fifo = $run(ValuationMethod::Fifo, ['item' => 'item-a']);
    $lifo = $run(ValuationMethod::Lifo, ['item' => 'item-a']);
    $all = $run(ValuationMethod::Fifo);
    $byWarehouse = $run(ValuationMethod::Lifo, ['warehouse' => 'wh-2']);

    expect($wac->simulated_summary)->toBe(['method' => 'WAC', 'total_value' => '150.00', 'total_quantity' => '10.0000'])
        ->and($wac->baseline_summary)->toBe(['method' => 'OFFICIAL', 'total_value' => '150.00', 'total_quantity' => '10.0000'])
        ->and($wac->delta_summary)->toBe(['total_value' => '0.00', 'pct' => '0.00'])
        // FIFO consumes the 10 @ 10: 10 @ 20 are left. LIFO consumes the 10 @ 20: 10 @ 10 are left.
        ->and($fifo->simulated_summary['total_value'])->toBe('200.00')
        ->and($fifo->delta_summary)->toBe(['total_value' => '50.00', 'pct' => '33.33'])
        ->and($lifo->simulated_summary['total_value'])->toBe('100.00')
        ->and($lifo->delta_summary)->toBe(['total_value' => '-50.00', 'pct' => '-33.33'])
        ->and($fifo->items)->toBe([['item_id' => 'item-a', 'warehouse_id' => 'wh-1', 'quantity' => '10.0000', 'official_value' => '150.00', 'simulated_value' => '200.00', 'variance' => '50.00', 'variance_pct' => '33.33']])
        ->and($all->simulated_summary)->toBe(['method' => 'FIFO', 'total_value' => '240.00', 'total_quantity' => '14.0000'])
        ->and($byWarehouse->items)->toHaveCount(1)
        ->and($byWarehouse->items[0]['item_id'])->toBe('item-b')
        ->and($byWarehouse->valuation_method)->toBe('LIFO')
        ->and($byWarehouse->created_by)->toBe((string) $this->user->id);

    // The simulation is isolated: it appended no event, wrote no movement and left only its own runs.
    expect(BiTemporalEvent::withoutGlobalScopes()->count())->toBe($before)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore)
        ->and(WhatIfSimulationRun::query()->count())->toBe(5);
});

it('revalues the layers spread over the quantity on hand when a cost update arrives', function (): void {
    ttStock($this, $this->tid, 'mv-1', '2026-01-02 08:00:00', '2026-01-02 08:00:00', 'item-a', 'wh-1', '10', '100.00', '10');
    ttRecord($this, $this->tid, AggregateType::InventoryWms, 'rv-1', 'StandardCostUpdated', '2026-01-03', '2026-01-03 08:00:00', ['revaluation_id' => 'rv-1', 'legs' => [['item_id' => 'item-a', 'warehouse_id' => 'wh-1', 'quantity_delta' => '0', 'value_delta' => '20.00']]]);
    ttStock($this, $this->tid, 'mv-2', '2026-01-04 08:00:00', '2026-01-04 08:00:00', 'item-a', 'wh-1', '-5', '-60.00', '12');

    $fifo = app(RunWhatIfValuationReplayHandler::class)->handle(new RunWhatIfValuationReplayCommand($this->tid, BiTemporalCoordinate::of('2026-01-31', '2026-02-01'), ValuationMethod::Fifo));

    // 10 @ 12 after the revaluation; 5 issued at 12 leaves 5 @ 12 = 60.00; the books hold 100 + 20 - 60 = 60.00.
    expect($fifo->simulated_summary['total_value'])->toBe('60.00')->and($fifo->baseline_summary['total_value'])->toBe('60.00');
});
