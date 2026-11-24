<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Commands;

use Modules\AgentCommissions\Services\CommissionAccrualService;

final class SyncCommissionsHandler
{
    public function __construct(private readonly CommissionAccrualService $accruals) {}

    /**
     * @return array{period_invoices: int, open_invoices: int}
     */
    public function handle(SyncCommissionsCommand $command): array
    {
        $period = $command->from !== null && $command->to !== null ? $this->accruals->syncPeriod($command->from, $command->to) : 0;

        return ['period_invoices' => $period, 'open_invoices' => $this->accruals->syncOpen()];
    }
}
