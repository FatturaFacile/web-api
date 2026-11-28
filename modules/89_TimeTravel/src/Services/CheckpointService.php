<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Domain\ValueObjects\MaterializedStateCheckpoint;
use Modules\TimeTravel\Models\MaterializedCheckpoint;
use Modules\TimeTravel\Support\Instant;
use Modules\TimeTravel\Support\MerkleTree;

/**
 * Materializes the folded state of a stream at a bi-temporal coordinate by replaying the events from the beginning (never from another
 * checkpoint, so a checkpoint is always an independent proof of the stream), with the Merkle root of the chain hashes it covers.
 */
final readonly class CheckpointService
{
    public function __construct(private EventStreamReader $reader, private ProjectorRegistry $projectors) {}

    public function generate(string $tenantId, AggregateType $type, ?CarbonImmutable $validAt = null, ?CarbonImmutable $knownAt = null): MaterializedStateCheckpoint
    {
        $now = Instant::now();
        $coordinate = new BiTemporalCoordinate($validAt ?? $now, $knownAt ?? $now);
        $projector = $this->projectors->for($type);

        $state = $projector->initialState($projector->checkpointDomain(), []);
        $leaves = [];
        $last = 0;

        foreach ($this->reader->visibleAt($tenantId, $type, $coordinate) as $event) {
            $state = $projector->apply($state, $event);
            $leaves[] = $event->cryptographic_chain_hash;
            $last = max($last, $event->sequence_number);
        }

        $model = MaterializedCheckpoint::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'aggregate_type' => $type->value,
            'valid_at' => Instant::db($coordinate->validAt),
            'recorded_at' => Instant::db($coordinate->knownAt),
            'state_payload' => $state,
            'merkle_root' => MerkleTree::root($leaves),
            'event_count' => count($leaves),
            'last_sequence_number' => $last,
            'created_at' => $now,
        ]);

        return MaterializedStateCheckpoint::fromModel($model);
    }

    /**
     * @param  list<AggregateType>|null  $types
     * @return list<MaterializedStateCheckpoint>
     */
    public function generateAll(string $tenantId, ?array $types = null, ?CarbonImmutable $validAt = null, ?CarbonImmutable $knownAt = null): array
    {
        $checkpoints = [];

        foreach ($types ?? AggregateType::cases() as $type) {
            $checkpoints[] = $this->generate($tenantId, $type, $validAt, $knownAt);
        }

        return $checkpoints;
    }
}
