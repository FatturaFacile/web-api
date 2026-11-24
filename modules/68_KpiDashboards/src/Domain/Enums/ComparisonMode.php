<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\Enums;

use Carbon\CarbonImmutable;

enum ComparisonMode: string
{
    case Yoy = 'yoy';
    case Mom = 'mom';
    case Ytd = 'ytd';
    case Budget = 'budget';

    /**
     * The period the current one is compared with; budget comparison has no prior period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function priorPeriod(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        return match ($this) {
            self::Yoy => [$from->subYear(), $to->subYear()],
            self::Mom => [$from->subMonthNoOverflow(), $to->subMonthNoOverflow()],
            self::Ytd => [$from->subYear()->startOfYear(), $to->subYear()],
            self::Budget => null,
        };
    }
}
