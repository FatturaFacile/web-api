<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use App\Models\ExchangeRate as ExchangeRateRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Treasury\Currency\Application\Queries\ListRatesQuery;

/**
 * Lists the rates a company sees: the official ones and its own, never another company's.
 */
final readonly class ListRatesHandler
{
    /**
     * @return LengthAwarePaginator<int, ExchangeRateRecord>
     */
    public function handle(ListRatesQuery $query): LengthAwarePaginator
    {
        return ExchangeRateRecord::query()
            ->withoutGlobalScopes()
            ->where(static function (Builder $scope) use ($query): void {
                $scope->whereNull('tenant_id')->orWhere('tenant_id', $query->tenantId);
            })
            ->when($query->base !== null, static fn (Builder $builder) => $builder->where('source', strtoupper((string) $query->base)))
            ->when($query->quote !== null, static fn (Builder $builder) => $builder->where('target', strtoupper((string) $query->quote)))
            ->when($query->type !== null, static fn (Builder $builder) => $builder->where('rate_type', $query->type?->value))
            ->when($query->from !== null, static fn (Builder $builder) => $builder->whereDate('effective_date', '>=', (string) $query->from))
            ->when($query->to !== null, static fn (Builder $builder) => $builder->whereDate('effective_date', '<=', (string) $query->to))
            ->when(! $query->includeSuperseded, static fn (Builder $builder) => $builder->whereNull('superseded_by'))
            ->orderByDesc('effective_date')
            ->orderBy('source')
            ->orderBy('target')
            ->orderByDesc('version')
            ->paginate($query->perPage);
    }
}
