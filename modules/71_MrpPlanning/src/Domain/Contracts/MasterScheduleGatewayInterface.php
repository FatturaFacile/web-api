<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Contracts;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\ValueObjects\DemandLine;

/**
 * Port towards the Master Production Schedule (Mod 062).
 */
interface MasterScheduleGatewayInterface
{
    /**
     * MPS lines due in the window as independent demand of manufacturing items.
     *
     * @return list<DemandLine>
     */
    public function demandBetween(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array;
}
