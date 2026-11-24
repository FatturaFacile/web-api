<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Period-end WIP valuation and posting for one project, or for every open project when no project is given (Mod 081).
 */
final readonly class RunProjectWipRecognitionCommand
{
    public function __construct(
        public ?string $projectId,
        public string $periodEndDate,
        public ?string $valuationMethod,
        public string $userId,
    ) {}
}
