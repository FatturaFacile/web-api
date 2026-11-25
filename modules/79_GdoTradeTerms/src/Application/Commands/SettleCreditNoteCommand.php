<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class SettleCreditNoteCommand
{
    public function __construct(
        public string $settlementId,
        public string $taxRate,
        public ?string $settledOn,
        public ?string $userId,
    ) {}
}
