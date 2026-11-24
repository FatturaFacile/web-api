<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

final readonly class HashChainVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly int $entriesChecked,
        public readonly ?string $brokenAtId,
    ) {}

    /** @return array{verified: bool, entries_checked: int, broken_at_id: string|null} */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'entries_checked' => $this->entriesChecked,
            'broken_at_id' => $this->brokenAtId,
        ];
    }
}
