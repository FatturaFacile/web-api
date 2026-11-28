<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Models\MaterializedCheckpoint;
use Modules\TimeTravel\Models\WhatIfSimulationRun;

require_once __DIR__.'/TimeTravelSupport.php';

beforeEach(function (): void {
    ttMember($this);
    $this->tid = (string) $this->tenant->id;

    ttJournal($this, $this->tid, 'je-1', '2025-12-10', '2025-12-10 09:00:00', [['cash', '1000.00', '0'], ['capital', '0', '1000.00']]);
    ttJournal($this, $this->tid, 'je-2', '2025-12-28', '2026-03-05 09:00:00', [['cash', '250.00', '0'], ['sales', '0', '250.00']]);
    ttStock($this, $this->tid, 'mv-1', '2026-01-02 08:00:00', '2026-01-02 08:00:00', 'item-a', 'wh-1', '10', '100.00', '10');
    ttStock($this, $this->tid, 'mv-2', '2026-01-03 08:00:00', '2026-01-03 08:00:00', 'item-a', 'wh-1', '10', '200.00', '20');
    ttStock($this, $this->tid, 'mv-3', '2026-01-04 08:00:00', '2026-01-04 08:00:00', 'item-a', 'wh-1', '-10', '-150.00', '15');
});

it('serves the trial balance at a bi-temporal coordinate in the standard envelope', function (): void {
    $response = $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2025-12-31&known_at=2026-01-15');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.domain', 'trial_balance')
        ->assertJsonPath('data.totals.debit', '1000.00')
        ->assertJsonPath('data.coordinate.valid_at', '2025-12-31T23:59:59.999999Z')
        ->assertJsonPath('meta.event_count', 1)
        ->assertJsonPath('meta.checkpoint_used', false)
        ->assertJsonStructure(['data' => ['rows' => [['account_id', 'account_code', 'account_name', 'debit', 'credit', 'balance']]], 'meta' => ['duration_ms']]);

    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2025-12-31&known_at=2026-03-31')
        ->assertOk()
        ->assertJsonPath('data.totals.debit', '1250.00')
        ->assertJsonPath('meta.event_count', 2);
});

it('serves the inventory, the aging and the fixed assets domains', function (): void {
    $this->getJson(TT_API.'/state?domain=inventory&valid_at=2026-01-31&known_at=2026-02-01&warehouse_id=wh-1')
        ->assertOk()
        ->assertJsonPath('data.totals.quantity', '10.0000')
        ->assertJsonPath('data.totals.value', '150.00');

    $this->getJson(TT_API.'/state?domain=aging&valid_at=2026-01-31&known_at=2026-02-01&partner_type=customer')->assertOk()->assertJsonPath('data.rows', []);
    $this->getJson(TT_API.'/state?domain=fixed_assets&valid_at=2026-01-31&known_at=2026-02-01')->assertOk()->assertJsonPath('data.totals.carrying_amount', '0.00');
});

it('validates the coordinate and the domain filters', function (): void {
    $this->getJson(TT_API.'/state?domain=nope&valid_at=2025-12-31&known_at=2026-01-15')->assertUnprocessable()->assertJsonValidationErrors('domain');
    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=not-a-date&known_at=2026-01-15')->assertUnprocessable()->assertJsonValidationErrors('valid_at');
    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2025-12-31')->assertUnprocessable()->assertJsonValidationErrors('known_at');
    $this->getJson(TT_API.'/state?domain=account_ledger&valid_at=2025-12-31&known_at=2026-01-15')->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->getJson(TT_API.'/state?domain=aging&valid_at=2025-12-31&known_at=2026-01-15&partner_type=alien')->assertUnprocessable()->assertJsonValidationErrors('partner_type');
});

it('serves the account ledger of an account', function (): void {
    $this->getJson(TT_API.'/state?domain=account_ledger&valid_at=2025-12-31&known_at=2026-03-31&account_id=cash')
        ->assertOk()
        ->assertJsonCount(2, 'data.rows')
        ->assertJsonPath('data.rows.1.running_balance', '1250.00');
});

it('serves the cut-off diff with the backdated entries', function (): void {
    $this->getJson(TT_API.'/cut-off-diff?domain=trial_balance&valid_at=2025-12-31&known_at_from=2026-01-15&known_at_to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('data.totals.delta', '250.00')
        ->assertJsonCount(1, 'data.backdated_entries')
        ->assertJsonPath('data.backdated_entries.0.aggregate_id', 'je-2')
        ->assertJsonPath('data.backdated_entries.0.event_type', 'JournalEntryPosted');

    $this->getJson(TT_API.'/cut-off-diff?domain=trial_balance&valid_at=2025-12-31&known_at_from=2026-03-31&known_at_to=2026-01-15')
        ->assertUnprocessable()->assertJsonValidationErrors('known_at_to');
});

it('runs a what-if replay, stores the run and never touches the books', function (): void {
    $events = DB::table('es_bitemporal_event_streams')->count();

    $this->postJson(TT_API.'/what-if-replay', ['valid_at' => '2026-01-31', 'known_at' => '2026-02-01', 'valuation_method' => 'FIFO', 'item_id' => 'item-a', 'simulation_name' => 'Year end FIFO'])
        ->assertCreated()
        ->assertJsonPath('data.simulation_name', 'Year end FIFO')
        ->assertJsonPath('data.valuation_method', 'FIFO')
        ->assertJsonPath('data.baseline_summary.total_value', '150.00')
        ->assertJsonPath('data.simulated_summary.total_value', '200.00')
        ->assertJsonPath('data.delta_summary.total_value', '50.00')
        ->assertJsonPath('data.items.0.variance', '50.00');

    expect(WhatIfSimulationRun::query()->count())->toBe(1)
        ->and(DB::table('es_bitemporal_event_streams')->count())->toBe($events);

    $this->postJson(TT_API.'/what-if-replay', ['valid_at' => '2026-01-31', 'known_at' => '2026-02-01', 'valuation_method' => 'HIFO'])
        ->assertUnprocessable()->assertJsonValidationErrors('valuation_method');
});

it('certifies the integrity of a range and reports a tampered chain', function (): void {
    $this->getJson(TT_API.'/audit-integrity?from_date=2025-12-01&to_date=2026-03-31')
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.events_checked', 5)
        ->assertJsonPath('data.broken_at', null)
        ->assertJsonPath('data.standard_codes.0', 'GEBUEV');

    DB::table('es_bitemporal_event_streams')->where('aggregate_id', 'je-1')->update(['event_type' => 'JournalEntryReversed']);

    $this->getJson(TT_API.'/audit-integrity?from_date=2025-12-01&to_date=2026-03-31')
        ->assertOk()
        ->assertJsonPath('data.verified', false)
        ->assertJsonPath('data.broken_at.reason', 'hash_mismatch')
        ->assertJsonPath('data.broken_at.sequence_number', 1);

    $this->getJson(TT_API.'/audit-integrity?from_date=2026-03-31&to_date=2025-12-01')->assertUnprocessable()->assertJsonValidationErrors('to_date');
    $this->getJson(TT_API.'/audit-integrity?from_date=2000-01-01&to_date=2026-03-31')->assertUnprocessable()->assertJsonValidationErrors('to_date');
});

it('materializes the checkpoints on demand', function (): void {
    $this->postJson(TT_API.'/checkpoints/generate')
        ->assertCreated()
        ->assertJsonCount(4, 'data.checkpoints')
        ->assertJsonPath('data.checkpoints.0.aggregate_type', 'general_ledger')
        ->assertJsonPath('data.checkpoints.0.event_count', 2);

    $this->postJson(TT_API.'/checkpoints/generate', ['aggregate_type' => 'inventory_wms'])
        ->assertCreated()
        ->assertJsonCount(1, 'data.checkpoints')
        ->assertJsonPath('data.checkpoints.0.event_count', 3)
        ->assertJsonPath('data.checkpoints.0.last_sequence_number', 3);

    expect(MaterializedCheckpoint::query()->count())->toBe(5);

    $this->postJson(TT_API.'/checkpoints/generate', ['aggregate_type' => 'bogus'])->assertUnprocessable();

    // The next reconstruction starts from the checkpoint and still matches the full replay.
    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2099-12-31&known_at=2099-12-31')
        ->assertOk()
        ->assertJsonPath('meta.checkpoint_used', true)
        ->assertJsonPath('data.totals.debit', '1250.00')
        ->and(AggregateType::values())->toHaveCount(4);
});

it('keeps another company out of the books of this one', function (): void {
    ttMember($this, 'owner', 'Altro');

    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2026-12-31&known_at=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.rows', [])
        ->assertJsonPath('meta.event_count', 0);

    $this->getJson(TT_API.'/audit-integrity?from_date=2025-12-01&to_date=2026-03-31')->assertOk()->assertJsonPath('data.events_checked', 0);
});

it('lets finance and auditors read, finance write, and nobody else in', function (): void {
    ttMember($this, 'accountant_readonly');
    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2026-12-31&known_at=2026-12-31')->assertOk();
    $this->postJson(TT_API.'/checkpoints/generate')->assertForbidden();
    $this->postJson(TT_API.'/what-if-replay', ['valid_at' => '2026-01-31', 'known_at' => '2026-02-01', 'valuation_method' => 'WAC'])->assertForbidden();

    ttMember($this, 'sales');
    $this->getJson(TT_API.'/state?domain=trial_balance&valid_at=2026-12-31&known_at=2026-12-31')->assertForbidden();
});
