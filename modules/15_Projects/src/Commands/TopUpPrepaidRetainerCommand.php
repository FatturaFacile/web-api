<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Records prepaid hours or amount received into the retainer of a project (Mod 081).
 */
final readonly class TopUpPrepaidRetainerCommand
{
    public function __construct(
        public string $projectId,
        public string $hours,
        public string $amount,
        public string $date,
        public ?string $note,
        public string $userId,
    ) {}
}
