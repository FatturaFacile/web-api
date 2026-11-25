<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class TransitionContractCommand
{
    public function __construct(
        public string $contractId,
        public string $action,
        public ?string $userId,
    ) {}
}
