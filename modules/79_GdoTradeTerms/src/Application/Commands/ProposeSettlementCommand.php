<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class ProposeSettlementCommand
{
    public function __construct(
        public string $contractId,
        public string $kind,
        public string $periodStart,
        public string $periodEnd,
        public ?string $userId,
    ) {}
}
