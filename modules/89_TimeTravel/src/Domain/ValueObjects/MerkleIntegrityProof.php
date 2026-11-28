<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Support\Instant;

/**
 * Outcome of the recomputation of the hash chains and of the Merkle roots over a range of dates.
 */
final readonly class MerkleIntegrityProof
{
    /**
     * @param  array{event_id: string, sequence_number: int, reason: string}|null  $brokenAt
     * @param  list<array{period_start: string, period_end: string, merkle_root: string, event_count: int, matches: bool}>  $anchors
     * @param  list<string>  $standardCodes
     */
    public function __construct(
        public bool $verified,
        public int $eventsChecked,
        public ?array $brokenAt,
        public ?string $merkleRoot,
        public array $anchors,
        public CarbonImmutable $generatedAt,
        public array $standardCodes = ['GEBUEV', 'CO_957A', 'CC_2214', 'ISA_ITALIA'],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'events_checked' => $this->eventsChecked,
            'broken_at' => $this->brokenAt,
            'merkle_root' => $this->merkleRoot,
            'anchors' => $this->anchors,
            'generated_at' => Instant::iso($this->generatedAt),
            'standard_codes' => $this->standardCodes,
        ];
    }
}
