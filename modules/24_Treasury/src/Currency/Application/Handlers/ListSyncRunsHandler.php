<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Treasury\Currency\Application\Queries\ListSyncRunsQuery;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateSyncRun;

/**
 * The history of the synchronisation runs a company sees: the scheduled ones of the platform and its own manual runs.
 */
final readonly class ListSyncRunsHandler
{
    /**
     * @return LengthAwarePaginator<int, ExchangeRateSyncRun>
     */
    public function handle(ListSyncRunsQuery $query): LengthAwarePaginator
    {
        return ExchangeRateSyncRun::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(static function (Builder $scope) use ($query): void {
                $scope->whereNull('tenant_id')->orWhere('tenant_id', $query->tenantId);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($query->perPage);
    }
}
