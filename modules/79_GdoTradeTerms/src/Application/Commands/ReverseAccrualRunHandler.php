<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Modules\GdoTradeTerms\Models\GdoAccrualRun;
use Modules\GdoTradeTerms\Services\AccrualService;

final class ReverseAccrualRunHandler
{
    public function __construct(private readonly AccrualService $accruals) {}

    public function handle(ReverseAccrualRunCommand $command): GdoAccrualRun
    {
        return $this->accruals->reverse(GdoAccrualRun::query()->findOrFail($command->runId), $command->reason, $command->userId);
    }
}
