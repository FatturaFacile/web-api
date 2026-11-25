<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class RunMonthlyAccrualCommand
{
    public function __construct(
        public string $tenantId,
        public string $periodEnd,
        public bool $recalculate,
        public ?string $userId,
    ) {}
}
