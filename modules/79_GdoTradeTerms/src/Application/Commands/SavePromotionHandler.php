<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoPromotion;
use Modules\GdoTradeTerms\Services\PromotionService;

final class SavePromotionHandler
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function handle(SavePromotionCommand $command): GdoPromotion
    {
        return $this->promotions->save(GdoContract::query()->findOrFail($command->contractId), $command->promotionId === null ? null : GdoPromotion::query()->where('contract_id', $command->contractId)->findOrFail($command->promotionId), $command->data, $command->userId);
    }
}
