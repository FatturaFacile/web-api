<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Models\MerkleAuditAnchor;
use Modules\TimeTravel\Services\MerkleAuditService;
use Modules\TimeTravel\Support\EventHasher;
use Modules\TimeTravel\Support\MerkleTree;

require_once __DIR__.'/TimeTravelSupport.php';

beforeEach(function (): void {
    ttMember($this);
});

function ttSeedChain(object $test, int $count = 4): void
{
    foreach (range(1, $count) as $n) {
        ttJournal($test, (string) $test->tenant->id, 'je-'.$n, '2026-01-0'.$n, '2026-01-0'.$n.' 10:00:00', [['cash', '10.00', '0'], ['sales', '0', '10.00']]);
    }
}

it('numbers the events of a stream monotonically and chains every hash to the previous one', function (): void {
    ttSeedChain($this);

    $events = BiTemporalEvent::query()->where('stream_id', 'general_ledger')->orderBy('sequence_number')->get();

    expect($events->pluck('sequence_number')->all())->toBe([1, 2, 3, 4])
        ->and($events[0]->previous_event_hash)->toBe(EventHasher::GENESIS);

    foreach ($events->skip(1) as $index => $event) {
        expect($event->previous_event_hash)->toBe($events[$index - 1]->cryptographic_chain_hash);
    }

    foreach ($events as $event) {
        expect($event->cryptographic_chain_hash)->toMatch('/^[0-9a-f]{64}$/');
    }
});

it('keeps an independent sequence and chain per stream', function (): void {
    ttSeedChain($this, 2);
    ttStock($this, (string) $this->tenant->id, 'mv-1', '2026-01-01 00:00:00', '2026-01-01 11:00:00', 'item', 'wh', '5', '50');

    $stock = BiTemporalEvent::query()->where('stream_id', 'inventory_wms')->first();

    expect($stock->sequence_number)->toBe(1)->and($stock->previous_event_hash)->toBe(EventHasher::GENESIS);
});

it('refuses to update or delete an event through the model', function (): void {
    ttSeedChain($this, 1);
    $event = BiTemporalEvent::query()->firstOrFail();

    expect(fn () => $event->update(['event_type' => 'Changed']))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $event->delete())->toThrow(LogicException::class, 'immutable');
});

it('refuses to update or delete a sealed anchor', function (): void {
    $anchor = MerkleAuditAnchor::withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id,
        'period_type' => 'day',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-01',
        'merkle_root' => str_repeat('a', 64),
        'event_count' => 1,
        'sealed_at' => '2026-01-02 00:00:00.000000',
    ]);

    expect(fn () => $anchor->update(['merkle_root' => str_repeat('b', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $anchor->delete())->toThrow(LogicException::class);
});

it('certifies an untouched chain over a range of dates', function (): void {
    ttSeedChain($this);

    $proof = app(MerkleAuditService::class)->verify((string) $this->tenant->id, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect($proof->verified)->toBeTrue()
        ->and($proof->eventsChecked)->toBe(4)
        ->and($proof->brokenAt)->toBeNull()
        ->and($proof->merkleRoot)->toMatch('/^[0-9a-f]{64}$/');
});

it('detects a payload altered behind the application (raw SQL)', function (): void {
    ttSeedChain($this);
    $victim = BiTemporalEvent::query()->where('sequence_number', 2)->firstOrFail();

    DB::table('es_bitemporal_event_streams')->where('id', $victim->id)->update(['payload' => json_encode(['journal_entry_id' => 'je-2', 'lines' => [['account_id' => 'cash', 'debit' => '999.00', 'credit' => '0']]])]);

    $proof = app(MerkleAuditService::class)->verify((string) $this->tenant->id, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect($proof->verified)->toBeFalse()
        ->and($proof->brokenAt['reason'])->toBe('hash_mismatch')
        ->and($proof->brokenAt['event_id'])->toBe((string) $victim->id)
        ->and($proof->brokenAt['sequence_number'])->toBe(2);
});

it('detects a deleted event as a gap in the sequence', function (): void {
    ttSeedChain($this);
    DB::table('es_bitemporal_event_streams')->where('sequence_number', 2)->delete();

    $proof = app(MerkleAuditService::class)->verify((string) $this->tenant->id, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect($proof->verified)->toBeFalse()->and($proof->brokenAt['reason'])->toBe('sequence_gap');
});

it('detects a rewritten link to the previous event', function (): void {
    ttSeedChain($this);
    DB::table('es_bitemporal_event_streams')->where('sequence_number', 3)->update(['previous_event_hash' => str_repeat('0', 64)]);

    $proof = app(MerkleAuditService::class)->verify((string) $this->tenant->id, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect($proof->verified)->toBeFalse()->and($proof->brokenAt['reason'])->toBeIn(['chain_link_broken', 'hash_mismatch']);
});

it('seals a closed period with the Merkle root of its events and detects tampering of a sealed period', function (): void {
    ttSeedChain($this, 3);
    $tenantId = (string) $this->tenant->id;

    $anchor = app(MerkleAuditService::class)->seal($tenantId, 'month', CarbonImmutable::parse('2026-01-15'));
    $leaves = BiTemporalEvent::query()->orderBy('recorded_at')->pluck('cryptographic_chain_hash')->all();

    expect($anchor)->not->toBeNull()
        ->and($anchor->event_count)->toBe(3)
        ->and($anchor->merkle_root)->toBe(MerkleTree::root($leaves))
        // Sealing twice returns the same anchor.
        ->and(app(MerkleAuditService::class)->seal($tenantId, 'month', CarbonImmutable::parse('2026-01-20'))->id)->toBe($anchor->id)
        ->and(app(MerkleAuditService::class)->verify($tenantId, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'))->verified)->toBeTrue();

    DB::table('es_merkle_audit_anchors')->where('id', $anchor->id)->update(['merkle_root' => str_repeat('f', 64)]);
    $proof = app(MerkleAuditService::class)->verify($tenantId, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));

    expect($proof->verified)->toBeFalse()
        ->and($proof->brokenAt['reason'])->toBe('anchor_mismatch')
        ->and($proof->anchors[0]['matches'])->toBeFalse();
});

it('does not seal a period that is not over yet', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    ttSeedChain($this, 1);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

    expect(app(MerkleAuditService::class)->seal((string) $this->tenant->id, 'day', CarbonImmutable::parse('2026-03-10')))->toBeNull();
});

it('hashes the same canonical form whatever the order of the keys', function (): void {
    expect(EventHasher::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]))->toBe(EventHasher::canonical(['a' => ['x' => 1, 'y' => 2], 'b' => 1]))
        ->and(MerkleTree::root([]))->toBe(hash('sha256', ''))
        ->and(MerkleTree::root(['aa']))->toBe('aa')
        ->and(MerkleTree::root(['aa', 'bb', 'cc']))->toBe(hash('sha256', hash('sha256', 'aabb').hash('sha256', 'cccc')));
});

it('isolates the event stream of a tenant from another', function (): void {
    ttSeedChain($this, 2);
    $own = (string) $this->tenant->id;

    ttMember($this, 'owner', 'Altro');
    $other = (string) $this->tenant->id;
    ttJournal($this, $other, 'je-x', '2026-01-05', '2026-01-05 10:00:00', [['cash', '1.00', '0'], ['sales', '0', '1.00']]);

    expect(BiTemporalEvent::query()->count())->toBe(1)
        ->and(BiTemporalEvent::withoutGlobalScopes()->where('tenant_id', $own)->count())->toBe(2)
        ->and(BiTemporalEvent::withoutGlobalScopes()->where('tenant_id', $other)->value('sequence_number'))->toBe(1)
        ->and(app(MerkleAuditService::class)->verify($other, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'))->eventsChecked)->toBe(1)
        ->and(AggregateType::values())->toHaveCount(4);
});
