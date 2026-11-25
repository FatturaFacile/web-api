<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Services\AccrualService;

final class RunMonthlyAccrualHandler
{
    public function __construct(private readonly AccrualService $accruals) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(RunMonthlyAccrualCommand $command): array
    {
        return $this->accruals->run($command->tenantId, Carbon::parse($command->periodEnd), $command->recalculate, false, $command->userId);
    }
}
