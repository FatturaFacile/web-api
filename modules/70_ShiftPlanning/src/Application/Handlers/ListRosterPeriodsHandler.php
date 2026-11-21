<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Database\Eloquent\Builder;
use Modules\ShiftPlanning\Application\Queries\ListRosterPeriodsQuery;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;

final class ListRosterPeriodsHandler
{
    private const int LIMIT = 200;

    /**
     * @return list<RosterSchedulePeriod>
     */
    public function handle(ListRosterPeriodsQuery $query): array
    {
        return RosterSchedulePeriod::query()
            ->where('tenant_id', $query->tenantId)
            ->when($query->periodType !== null, static fn (Builder $q) => $q->where('period_type', $query->periodType))
            ->when($query->from !== null, static fn (Builder $q) => $q->whereDate('end_date', '>=', $query->from?->format('Y-m-d')))
            ->when($query->to !== null, static fn (Builder $q) => $q->whereDate('start_date', '<=', $query->to?->format('Y-m-d')))
            ->orderByDesc('start_date')
            ->limit(self::LIMIT)
            ->get()
            ->all();
    }
}
