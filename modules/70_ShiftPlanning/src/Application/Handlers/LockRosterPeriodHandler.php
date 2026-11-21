<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ShiftPlanning\Application\Commands\LockRosterPeriodCommand;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;

/**
 * Freezes a published roster as the baseline handed to payroll: no assignment, claim or swap changes it afterwards.
 */
final class LockRosterPeriodHandler
{
    /**
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(LockRosterPeriodCommand $command): RosterSchedulePeriod
    {
        return DB::transaction(function () use ($command): RosterSchedulePeriod {
            $period = RosterSchedulePeriod::query()->where('tenant_id', $command->tenantId)->whereKey($command->periodId)->lockForUpdate()->first()
                ?? throw new ShiftNotFoundException('Unknown roster period.');
            $period->lock($command->userId);

            return $period;
        });
    }
}
