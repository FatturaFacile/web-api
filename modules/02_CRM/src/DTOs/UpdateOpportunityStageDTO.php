<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class UpdateOpportunityStageDTO
{
    public function __construct(
        public string $targetStageCode,
        public ?float $amount = null,
        public ?float $probability = null,
        public ?string $forecastCategory = null,
        public ?string $winLossReason = null,
        public ?string $competitor = null,
        public ?string $actualCloseDate = null,
        public ?string $lossReasonCode = null,
        public ?string $lossNotes = null,
        public ?int $position = null
    ) {}
}
