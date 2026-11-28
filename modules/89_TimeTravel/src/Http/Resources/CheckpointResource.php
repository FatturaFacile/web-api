<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TimeTravel\Domain\ValueObjects\MaterializedStateCheckpoint;
use Modules\TimeTravel\Support\Instant;

final class CheckpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaterializedStateCheckpoint $checkpoint */
        $checkpoint = $this->resource;

        return [
            'id' => $checkpoint->id,
            'aggregate_type' => $checkpoint->aggregateType->value,
            'valid_at' => Instant::iso($checkpoint->validAt),
            'recorded_at' => Instant::iso($checkpoint->recordedAt),
            'event_count' => $checkpoint->eventCount,
            'merkle_root' => $checkpoint->merkleRoot,
            'last_sequence_number' => $checkpoint->lastSequenceNumber,
        ];
    }
}
