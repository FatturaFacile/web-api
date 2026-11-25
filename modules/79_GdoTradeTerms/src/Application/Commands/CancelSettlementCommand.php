<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class CancelSettlementCommand
{
    public function __construct(
        public string $settlementId,
        public ?string $userId,
    ) {}
}
