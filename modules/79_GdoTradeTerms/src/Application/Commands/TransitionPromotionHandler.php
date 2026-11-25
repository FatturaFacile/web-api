<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Services\PromotionService;

final class TransitionPromotionHandler
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function handle(TransitionPromotionCommand $command): GdoPromotion
    {
        return $this->promotions->transition(GdoPromotion::query()->findOrFail($command->promotionId), $command->action, $command->userId);
    }
}
