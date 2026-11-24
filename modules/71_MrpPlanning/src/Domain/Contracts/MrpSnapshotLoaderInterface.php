<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\ValueObjects\MrpSnapshot;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Domain\ValueObjects\WorkCenterCalendar;

/**
 * Loads the planning input of a tenant from the upstream modules (BOM, work centres, inventory, sales, procurement,
 * production orders, master production schedule).
 */
interface MrpSnapshotLoaderInterface
{
    public function load(string $tenantId, RunParameters $parameters): MrpSnapshot;

    /**
     * Daily capacity calendars of work centres (Mod 061) for a window.
     *
     * @param  list<string>  $workCenterIds
     * @return array<string, WorkCenterCalendar>
     */
    public function calendars(array $workCenterIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
