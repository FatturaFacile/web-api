<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\MerkleIntegrityProof;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Models\MerkleAuditAnchor;
use Modules\TimeTravel\Support\EventHasher;
use Modules\TimeTravel\Support\Instant;
use Modules\TimeTravel\Support\MerkleTree;

/**
 * Seals closed audit periods with a Merkle root of the chain hashes of every event recorded in them, and certifies a range of dates by
 * recomputing, without trusting any stored hash: the chain hash of every event, the link to its predecessor, the continuity of the
 * sequence (a deleted event leaves a gap) and the roots of the sealed periods.
 */
final readonly class MerkleAuditService
{
    public function __construct(private EventStreamReader $reader) {}

    /**
     * Seals a closed period (its last day is before today, UTC) once; a sealed period is never sealed again.
     */
    public function seal(string $tenantId, string $periodType, CarbonImmutable $date): ?MerkleAuditAnchor
    {
        [$start, $end] = $this->bounds($periodType, $date);

        if ($end->greaterThanOrEqualTo(Instant::now()->startOfDay())) {
            return null;
        }

        $existing = MerkleAuditAnchor::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('period_type', $periodType)->where('period_start', $start->toDateString())->first();

        if ($existing !== null) {
            return $existing;
        }

        $leaves = $this->leaves($tenantId, $start, $end->endOfDay());

        if ($leaves === []) {
            return null;
        }

        return MerkleAuditAnchor::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'period_type' => $periodType,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'merkle_root' => MerkleTree::root($leaves),
            'event_count' => count($leaves),
            'sealed_at' => Instant::db(Instant::now()),
        ]);
    }

    /**
     * Seals every closed day (and month) that holds events and is not sealed yet.
     *
     * @return int the anchors created
     */
    public function sealClosedPeriods(string $tenantId): int
    {
        $first = BiTemporalEvent::withoutGlobalScopes()->where('tenant_id', $tenantId)->min('recorded_at');

        if ($first === null) {
            return 0;
        }

        $created = 0;
        $today = Instant::now()->startOfDay();

        for ($day = Instant::from((string) $first)->startOfDay(); $day->lessThan($today); $day = $day->addDay()) {
            $created += $this->sealIfMissing($tenantId, 'day', $day) ? 1 : 0;
        }

        for ($month = Instant::from((string) $first)->startOfMonth(); $month->endOfMonth()->startOfDay()->lessThan($today); $month = $month->addMonth()) {
            $created += $this->sealIfMissing($tenantId, 'month', $month) ? 1 : 0;
        }

        return $created;
    }

    public function verify(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): MerkleIntegrityProof
    {
        $rangeStart = $from->startOfDay();
        $rangeEnd = $to->endOfDay();
        $broken = null;
        $checked = 0;

        foreach (AggregateType::cases() as $type) {
            $previous = null;

            $events = $this->reader->stream($tenantId, $type)
                ->where('recorded_at', '>=', Instant::db($rangeStart))
                ->where('recorded_at', '<=', Instant::db($rangeEnd))
                ->orderBy('sequence_number')
                ->cursor();

            foreach ($events as $event) {
                $checked++;

                if ($previous === null) {
                    $predecessor = $event->sequence_number > 1
                        ? $this->reader->stream($tenantId, $type)->where('sequence_number', $event->sequence_number - 1)->first()
                        : null;

                    if ($event->sequence_number > 1 && $predecessor === null) {
                        $broken ??= $this->broken($event, 'sequence_gap');
                    } elseif ($predecessor !== null && $predecessor->cryptographic_chain_hash !== $event->previous_event_hash) {
                        $broken ??= $this->broken($event, 'chain_link_broken');
                    } elseif ($predecessor === null && $event->previous_event_hash !== EventHasher::GENESIS) {
                        $broken ??= $this->broken($event, 'chain_link_broken');
                    }
                } elseif ($event->sequence_number !== $previous->sequence_number + 1) {
                    $broken ??= $this->broken($event, 'sequence_gap');
                } elseif ($event->previous_event_hash !== $previous->cryptographic_chain_hash) {
                    $broken ??= $this->broken($event, 'chain_link_broken');
                }

                if (BiTemporalEventRecorder::hashOf($event) !== $event->cryptographic_chain_hash) {
                    $broken ??= $this->broken($event, 'hash_mismatch');
                }

                $previous = $event;
            }
        }

        $anchors = [];

        $sealed = MerkleAuditAnchor::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('period_start', '>=', $rangeStart->toDateString())
            ->whereDate('period_end', '<=', $rangeEnd->toDateString())
            ->orderBy('period_start')->orderBy('period_type')
            ->get();

        foreach ($sealed as $anchor) {
            $leaves = $this->leaves($tenantId, Instant::startOfDay((string) $anchor->period_start), Instant::startOfDay((string) $anchor->period_end)->endOfDay());
            $matches = count($leaves) === (int) $anchor->event_count && MerkleTree::root($leaves) === $anchor->merkle_root;
            $anchors[] = [
                'period_start' => substr((string) $anchor->period_start, 0, 10),
                'period_end' => substr((string) $anchor->period_end, 0, 10),
                'merkle_root' => (string) $anchor->merkle_root,
                'event_count' => (int) $anchor->event_count,
                'matches' => $matches,
            ];

            if (! $matches) {
                $broken ??= ['event_id' => '', 'sequence_number' => 0, 'reason' => 'anchor_mismatch'];
            }
        }

        $leaves = $this->leaves($tenantId, $rangeStart, $rangeEnd);

        return new MerkleIntegrityProof($broken === null, $checked, $broken, $leaves === [] ? null : MerkleTree::root($leaves), $anchors, Instant::now());
    }

    /**
     * The chain hashes of the events recorded in the window, across the streams, in recording order.
     *
     * @return list<string>
     */
    private function leaves(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $leaves = [];

        $events = BiTemporalEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('recorded_at', '>=', Instant::db($from))
            ->where('recorded_at', '<=', Instant::db($to))
            ->orderBy('recorded_at')->orderBy('stream_id')->orderBy('sequence_number')
            ->cursor();

        foreach ($events as $event) {
            $leaves[] = $event->cryptographic_chain_hash;
        }

        return $leaves;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(string $periodType, CarbonImmutable $date): array
    {
        $date = $date->utc()->startOfDay();

        return $periodType === 'month' ? [$date->startOfMonth(), $date->endOfMonth()->startOfDay()] : [$date, $date];
    }

    /**
     * @return array{event_id: string, sequence_number: int, reason: string}
     */
    private function broken(BiTemporalEvent $event, string $reason): array
    {
        return ['event_id' => (string) $event->id, 'sequence_number' => (int) $event->sequence_number, 'reason' => $reason];
    }

    private function sealIfMissing(string $tenantId, string $periodType, CarbonImmutable $date): bool
    {
        [$start] = $this->bounds($periodType, $date);

        $sealed = MerkleAuditAnchor::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('period_type', $periodType)->where('period_start', $start->toDateString())
            ->exists();

        return ! $sealed && $this->seal($tenantId, $periodType, $date) !== null;
    }
}
