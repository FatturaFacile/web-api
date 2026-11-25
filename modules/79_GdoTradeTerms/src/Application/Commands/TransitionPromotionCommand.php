<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

final readonly class TransitionPromotionCommand
{
    public function __construct(
        public string $promotionId,
        public string $action,
        public ?string $userId,
    ) {}
}
