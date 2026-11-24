<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class RoutingResult
{
    public function __construct(
        public ?string $assignedUserId,
        public ?string $ruleId,
        public bool $usedDefaultRule = false,
    ) {}

    public function isAssigned(): bool
    {
        return $this->assignedUserId !== null;
    }
}
