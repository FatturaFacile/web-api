<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoScale;
use Modules\GdoTradeTerms\Services\ScaleService;

final class SaveScaleHandler
{
    public function __construct(private readonly ScaleService $scales) {}

    public function handle(SaveScaleCommand $command): GdoScale
    {
        return $this->scales->save(GdoContract::query()->findOrFail($command->contractId), $command->scaleId === null ? null : GdoScale::query()->where('contract_id', $command->contractId)->findOrFail($command->scaleId), $command->data, $command->userId);
    }
}
