<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Services\SellOutService;

final class ImportSellOutHandler
{
    public function __construct(private readonly SellOutService $sellOut) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ImportSellOutCommand $command): array
    {
        return $this->sellOut->import(GdoContract::query()->findOrFail($command->contractId), $command->rows, $command->source, $command->userId);
    }
}
