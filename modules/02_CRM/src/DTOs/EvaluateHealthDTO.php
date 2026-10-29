<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class EvaluateHealthDTO
{
    public function __construct(
        public string $accountId,
        public ?float $openReceivableAmount = null,
        public ?float $overdueReceivableAmount = null,
        public ?int $completedActivitiesCount = null,
        public ?int $daysSinceLastInteraction = null
    ) {}
}
