<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;

/**
 * The roster periods of the tenant that overlap a window of dates, optionally of one type (weekly or monthly).
 */
final readonly class ListRosterPeriodsQuery
{
    public function __construct(
        public string $tenantId,
        public ?PeriodType $periodType = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}
}
