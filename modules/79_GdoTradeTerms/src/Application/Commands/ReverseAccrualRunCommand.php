<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class ReverseAccrualRunCommand
{
    public function __construct(
        public string $runId,
        public string $reason,
        public ?string $userId,
    ) {}
}
