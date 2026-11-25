<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class ResolveSettlementDisputeCommand
{
    public function __construct(
        public string $settlementId,
        public string $resolution,
        public ?string $notes,
        public ?string $userId,
    ) {}
}
