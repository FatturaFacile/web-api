<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Application\Queries\ReconstructStateAtBiTemporalCoordinateHandler;
use Modules\TimeTravel\Application\Queries\ReconstructStateAtBiTemporalCoordinateQuery;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Models\MaterializedCheckpoint;
use Modules\TimeTravel\Services\CheckpointService;

require_once __DIR__.'/TimeTravelSupport.php';

beforeEach(function (): void {
    ttMember($this);
    $this->tid = (string) $this->tenant->id;
});

function ttState(object $test, QueryDomain $domain, string $validAt, string $knownAt, array $filters = [], bool $checkpoints = true): array
{
    return app(ReconstructStateAtBiTemporalCoordinateHandler::class)->handle(new ReconstructStateAtBiTemporalCoordinateQuery(
        (string) $test->tenant->id,
        $domain,
        BiTemporalCoordinate::of($validAt, $knownAt),
        $filters,
        $checkpoints,
    ));
}

/**
 * Trial balance reduced to account => balance.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, string>
 */
function ttBalances(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (array $row): array => [$row['account_id'] => $row['balance']])->all();
}

it('reconstructs the trial balance by valid time and by known time', function (): void {
    // Booked on time.
    ttJournal($this, $this->tid, 'je-1', '2025-12-10', '2025-12-10 09:00:00', [['cash', '1000.00', '0'], ['capital', '0', '1000.00']]);
    // Dated within 2025 but only recorded in March: a backdated entry.
    ttJournal($this, $this->tid, 'je-2', '2025-12-28', '2026-03-05 09:00:00', [['cash', '250.00', '0'], ['sales', '0', '250.00']]);
    // Belongs to 2026.
    ttJournal($this, $this->tid, 'je-3', '2026-02-01', '2026-02-01 09:00:00', [['cash', '40.00', '0'], ['sales', '0', '40.00']]);

    $known15Jan = ttState($this, QueryDomain::TrialBalance, '2025-12-31', '2026-01-15');
    $known31Mar = ttState($this, QueryDomain::TrialBalance, '2025-12-31', '2026-03-31');
    $live = ttState($this, QueryDomain::TrialBalance, '2026-12-31', '2026-12-31');

    expect(ttBalances($known15Jan['rows']))->toBe(['capital' => '-1000.00', 'cash' => '1000.00'])
        ->and(ttBalances($known31Mar['rows']))->toBe(['capital' => '-1000.00', 'cash' => '1250.00', 'sales' => '-250.00'])
        ->and(ttBalances($live['rows']))->toBe(['capital' => '-1000.00', 'cash' => '1290.00', 'sales' => '-290.00'])
        ->and($known31Mar['totals'])->toBe(['debit' => '1250.00', 'credit' => '1250.00', 'balance' => '0.00'])
        ->and($known15Jan['event_count'])->toBe(1)
        ->and($known31Mar['event_count'])->toBe(2)
        ->and($known31Mar['checkpoint_used'])->toBeFalse();
});

it('treats a date without time as the end of that day', function (): void {
    ttJournal($this, $this->tid, 'je-1', '2025-12-31', '2025-12-31 09:00:00', [['cash', '5.00', '0'], ['sales', '0', '5.00']]);

    expect(ttBalances(ttState($this, QueryDomain::TrialBalance, '2025-12-31', '2026-01-02')['rows']))->toBe(['cash' => '5.00', 'sales' => '-5.00'])
        ->and(ttState($this, QueryDomain::TrialBalance, '2025-12-30', '2026-01-02')['rows'])->toBe([]);
});

it('lists the entries of an account with the running balance as known at a moment', function (): void {
    ttJournal($this, $this->tid, 'je-1', '2025-11-01', '2025-11-01 09:00:00', [['cash', '100.00', '0'], ['sales', '0', '100.00']]);
    ttJournal($this, $this->tid, 'je-2', '2025-12-01', '2025-12-01 09:00:00', [['cash', '0', '30.00'], ['rent', '30.00', '0']]);
    ttJournal($this, $this->tid, 'je-3', '2025-11-15', '2026-03-01 09:00:00', [['cash', '10.00', '0'], ['sales', '0', '10.00']]);

    $before = ttState($this, QueryDomain::AccountLedger, '2025-12-31', '2026-01-31', ['account_id' => 'cash']);
    $after = ttState($this, QueryDomain::AccountLedger, '2025-12-31', '2026-04-30', ['account_id' => 'cash']);

    expect(collect($before['rows'])->pluck('entry_number')->all())->toBe(['JE-je-1', 'JE-je-2'])
        ->and(collect($before['rows'])->pluck('running_balance')->all())->toBe(['100.00', '70.00'])
        ->and(collect($after['rows'])->pluck('entry_number')->all())->toBe(['JE-je-1', 'JE-je-3', 'JE-je-2'])
        ->and(collect($after['rows'])->pluck('running_balance')->all())->toBe(['100.00', '110.00', '80.00'])
        ->and($after['totals'])->toBe(['debit' => '110.00', 'credit' => '30.00', 'balance' => '80.00']);
});

it('reconstructs the aging with payments, credit notes and credit limits as known', function (): void {
    $invoice = fn (string $id, string $number, string $due, string $gross, string $recorded) => ttRecord($this, $this->tid, AggregateType::OpenItems, $id, 'InvoiceIssued', '2026-01-01', $recorded, [
        'document_id' => $id, 'document_number' => $number, 'partner_id' => 'cust-1', 'partner_type' => 'customer', 'issue_date' => '2026-01-01', 'due_date' => $due, 'currency' => 'EUR', 'gross' => $gross,
    ]);

    $invoice('inv-1', 'FT-1', '2026-01-31', '1000.00', '2026-01-01 10:00:00');
    $invoice('inv-2', 'FT-2', '2026-03-15', '500.00', '2026-01-01 10:01:00');
    ttRecord($this, $this->tid, AggregateType::OpenItems, 'inv-1', 'PaymentReconciled', '2026-02-10', '2026-02-10 08:00:00', ['document_id' => 'inv-1', 'partner_type' => 'customer', 'paid_amount' => '400.00']);
    ttRecord($this, $this->tid, AggregateType::OpenItems, 'cn-1', 'CreditNoteIssued', '2026-02-20', '2026-02-20 08:00:00', ['credit_note_id' => 'cn-1', 'credit_note_number' => 'NC-1', 'original_invoice_id' => 'inv-2', 'partner_id' => 'cust-1', 'credited_amount' => '100.00']);
    ttRecord($this, $this->tid, AggregateType::OpenItems, 'cust-1', 'CreditLimitAdjusted', '2026-02-01', '2026-02-01 08:00:00', ['customer_id' => 'cust-1', 'old_limit' => '5000.00', 'new_limit' => '8000.00']);

    $january = ttState($this, QueryDomain::Aging, '2026-01-31', '2026-01-31');
    $april = ttState($this, QueryDomain::Aging, '2026-04-15', '2026-04-15', ['partner_type' => 'customer']);

    expect(collect($january['rows'])->pluck('open_amount', 'document_id')->all())->toBe(['inv-1' => '1000.00', 'inv-2' => '500.00'])
        ->and($january['totals']['open_amount'])->toBe('1500.00')
        ->and(collect($april['rows'])->pluck('open_amount', 'document_id')->all())->toBe(['inv-1' => '600.00', 'inv-2' => '400.00'])
        ->and(collect($april['rows'])->pluck('bucket', 'document_id')->all())->toBe(['inv-1' => '61_90', 'inv-2' => '31_60'])
        ->and($april['totals']['buckets']['90_plus'])->toBe('0.00')
        ->and($april['totals']['credit_limits'])->toBe(['cust-1' => '8000.00'])
        ->and(ttState($this, QueryDomain::Aging, '2026-04-15', '2026-04-15', ['partner_type' => 'supplier'])['rows'])->toBe([]);
});

it('reconstructs the inventory quantities and values per item, warehouse and lot', function (): void {
    ttStock($this, $this->tid, 'mv-1', '2026-01-05 08:00:00', '2026-01-05 08:00:00', 'item-a', 'wh-1', '10', '100.00', '10');
    ttStock($this, $this->tid, 'mv-2', '2026-01-10 08:00:00', '2026-01-10 08:00:00', 'item-a', 'wh-1', '-4', '-40.00', '10');
    ttStock($this, $this->tid, 'mv-3', '2026-01-12 08:00:00', '2026-01-12 08:00:00', 'item-b', 'wh-2', '3', '90.00', '30');
    ttRecord($this, $this->tid, AggregateType::InventoryWms, 'rv-1', 'StandardCostUpdated', '2026-01-20', '2026-01-20 08:00:00', ['revaluation_id' => 'rv-1', 'legs' => [['item_id' => 'item-a', 'warehouse_id' => 'wh-1', 'quantity_delta' => '0', 'value_delta' => '12.00']]]);

    $mid = ttState($this, QueryDomain::Inventory, '2026-01-11', '2026-02-01');
    $end = ttState($this, QueryDomain::Inventory, '2026-01-31', '2026-02-01');
    $onlyB = ttState($this, QueryDomain::Inventory, '2026-01-31', '2026-02-01', ['item_id' => 'item-b']);

    expect(collect($mid['rows'])->map(fn (array $row): string => $row['item_id'].'@'.$row['warehouse_id'].' '.$row['quantity'].' '.$row['value'])->all())->toBe(['item-a@wh-1 6.0000 60.00'])
        ->and($end['totals'])->toBe(['quantity' => '9.0000', 'value' => '162.00'])
        ->and($end['rows'][0]['value'])->toBe('72.00')
        ->and($end['rows'][0]['unit_cost'])->toBe('12.0000')
        ->and($onlyB['totals'])->toBe(['quantity' => '3.0000', 'value' => '90.00']);
});

it('reconstructs the fixed assets with depreciation, revaluation and disposal', function (): void {
    ttRecord($this, $this->tid, AggregateType::FixedAssets, 'as-1', 'AssetAcquired', '2025-01-01', '2025-01-01 08:00:00', ['asset_id' => 'as-1', 'asset_number' => 'FA-1', 'name' => 'Press', 'cost' => '12000.00']);
    ttRecord($this, $this->tid, AggregateType::FixedAssets, 'as-1', 'AssetDepreciated', '2025-12-31', '2025-12-31 20:00:00', ['asset_id' => 'as-1', 'depreciation_amount' => '2400.00']);
    ttRecord($this, $this->tid, AggregateType::FixedAssets, 'as-1', 'AssetImpaired', '2026-03-31', '2026-03-31 20:00:00', ['asset_id' => 'as-1', 'adjustment' => '-600.00']);
    ttRecord($this, $this->tid, AggregateType::FixedAssets, 'as-2', 'AssetAcquired', '2025-06-01', '2025-06-01 08:00:00', ['asset_id' => 'as-2', 'asset_number' => 'FA-2', 'name' => 'Van', 'cost' => '30000.00']);
    ttRecord($this, $this->tid, AggregateType::FixedAssets, 'as-2', 'AssetDisposed', '2026-02-01', '2026-02-01 20:00:00', ['asset_id' => 'as-2']);

    $year = ttState($this, QueryDomain::FixedAssets, '2025-12-31', '2026-06-01');
    $later = ttState($this, QueryDomain::FixedAssets, '2026-12-31', '2026-06-01');

    expect(collect($year['rows'])->pluck('carrying_amount', 'asset_number')->all())->toBe(['FA-1' => '9600.00', 'FA-2' => '30000.00'])
        ->and(collect($later['rows'])->pluck('carrying_amount', 'asset_number')->all())->toBe(['FA-1' => '9000.00', 'FA-2' => '0.00'])
        ->and(collect($later['rows'])->pluck('status', 'asset_number')->all())->toBe(['FA-1' => 'active', 'FA-2' => 'disposed'])
        ->and($later['totals']['carrying_amount'])->toBe('9000.00');
});

it('gives the same state from a checkpoint plus the delta as from the full stream, backdated entries included', function (): void {
    ttJournal($this, $this->tid, 'je-1', '2025-12-10', '2025-12-10 09:00:00', [['cash', '1000.00', '0'], ['capital', '0', '1000.00']]);
    ttJournal($this, $this->tid, 'je-2', '2026-01-10', '2026-01-10 09:00:00', [['cash', '200.00', '0'], ['sales', '0', '200.00']]);

    $checkpoint = app(CheckpointService::class)->generate($this->tid, AggregateType::GeneralLedger, CarbonImmutable::parse('2026-01-31', 'UTC'), CarbonImmutable::parse('2026-02-01', 'UTC'));

    // After the checkpoint: a normal entry, an entry backdated before the checkpoint's valid time (recorded later), and a future-dated one.
    ttJournal($this, $this->tid, 'je-3', '2026-02-10', '2026-02-10 09:00:00', [['cash', '30.00', '0'], ['sales', '0', '30.00']]);
    ttJournal($this, $this->tid, 'je-4', '2025-12-20', '2026-02-12 09:00:00', [['rent', '70.00', '0'], ['cash', '0', '70.00']]);
    ttJournal($this, $this->tid, 'je-5', '2026-06-01', '2026-02-13 09:00:00', [['cash', '5.00', '0'], ['sales', '0', '5.00']]);

    expect($checkpoint->eventCount)->toBe(2)->and($checkpoint->lastSequenceNumber)->toBe(2);

    foreach ([['2026-12-31', '2026-12-31'], ['2026-02-28', '2026-02-11'], ['2025-12-31', '2026-03-01'], ['2026-01-31', '2026-02-01']] as [$validAt, $knownAt]) {
        $fast = ttState($this, QueryDomain::TrialBalance, $validAt, $knownAt);
        $full = ttState($this, QueryDomain::TrialBalance, $validAt, $knownAt, checkpoints: false);

        expect($fast['rows'])->toBe($full['rows'], "rows at {$validAt} / {$knownAt}")
            ->and($fast['totals'])->toBe($full['totals'])
            ->and($fast['event_count'])->toBe($full['event_count']);
    }

    $live = ttState($this, QueryDomain::TrialBalance, '2026-12-31', '2026-12-31');

    expect($live['checkpoint_used'])->toBeTrue()
        ->and($live['event_count'])->toBe(5)
        ->and(ttBalances($live['rows']))->toBe(['capital' => '-1000.00', 'cash' => '1165.00', 'rent' => '70.00', 'sales' => '-235.00'])
        // A checkpoint that is not inside the coordinate is never used.
        ->and(ttState($this, QueryDomain::TrialBalance, '2026-01-15', '2026-01-20')['checkpoint_used'])->toBeFalse();
});

it('checkpoints every stream with an independent Merkle root and keeps the delta replay exact for aging and inventory', function (): void {
    ttRecord($this, $this->tid, AggregateType::OpenItems, 'inv-1', 'InvoiceIssued', '2026-01-01', '2026-01-01 10:00:00', ['document_id' => 'inv-1', 'document_number' => 'FT-1', 'partner_id' => 'c', 'partner_type' => 'customer', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-31', 'gross' => '300.00']);
    ttStock($this, $this->tid, 'mv-1', '2026-01-05 08:00:00', '2026-01-05 08:00:00', 'item-a', 'wh-1', '10', '100.00');

    $checkpoints = app(CheckpointService::class)->generateAll($this->tid, null, CarbonImmutable::parse('2026-01-31', 'UTC'), CarbonImmutable::parse('2026-02-01', 'UTC'));

    ttRecord($this, $this->tid, AggregateType::OpenItems, 'inv-1', 'PaymentReconciled', '2026-02-10', '2026-02-10 08:00:00', ['document_id' => 'inv-1', 'paid_amount' => '100.00']);
    ttStock($this, $this->tid, 'mv-2', '2026-02-11 08:00:00', '2026-02-11 08:00:00', 'item-a', 'wh-1', '-2', '-20.00');

    expect($checkpoints)->toHaveCount(4)
        ->and(MaterializedCheckpoint::query()->count())->toBe(4);

    foreach ([QueryDomain::Aging, QueryDomain::Inventory] as $domain) {
        $fast = ttState($this, $domain, '2026-12-31', '2026-12-31');
        $full = ttState($this, $domain, '2026-12-31', '2026-12-31', checkpoints: false);

        expect($fast['checkpoint_used'])->toBeTrue()
            ->and($fast['rows'])->toBe($full['rows'])
            ->and($fast['totals'])->toBe($full['totals']);
    }
});

it('never reads the events of another tenant', function (): void {
    ttJournal($this, $this->tid, 'je-1', '2026-01-10', '2026-01-10 09:00:00', [['cash', '10.00', '0'], ['sales', '0', '10.00']]);
    $own = $this->tid;

    ttMember($this, 'owner', 'Altro');
    ttJournal($this, (string) $this->tenant->id, 'je-9', '2026-01-10', '2026-01-10 09:00:00', [['cash', '999.00', '0'], ['sales', '0', '999.00']]);

    $state = app(ReconstructStateAtBiTemporalCoordinateHandler::class)->handle(new ReconstructStateAtBiTemporalCoordinateQuery($own, QueryDomain::TrialBalance, BiTemporalCoordinate::of('2026-12-31', '2026-12-31')));

    expect(ttBalances($state['rows']))->toBe(['cash' => '10.00', 'sales' => '-10.00']);
});
