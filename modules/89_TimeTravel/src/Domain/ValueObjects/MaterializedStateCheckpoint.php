<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Models\MaterializedCheckpoint;
use Modules\TimeTravel\Support\Instant;

/**
 * The folded state of a stream at (validAt, recordedAt): every event with valid_at <= validAt AND recorded_at <= recordedAt.
 */
final readonly class MaterializedStateCheckpoint
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public string $id,
        public AggregateType $aggregateType,
        public CarbonImmutable $validAt,
        public CarbonImmutable $recordedAt,
        public array $state,
        public string $merkleRoot,
        public int $eventCount,
        public int $lastSequenceNumber,
    ) {}

    public static function fromModel(MaterializedCheckpoint $model): self
    {
        /** @var array<string, mixed> $state */
        $state = is_array($model->state_payload) ? $model->state_payload : [];

        return new self(
            (string) $model->id,
            AggregateType::from((string) $model->aggregate_type),
            Instant::from((string) $model->valid_at),
            Instant::from((string) $model->recorded_at),
            $state,
            (string) $model->merkle_root,
            (int) $model->event_count,
            (int) $model->last_sequence_number,
        );
    }
}
