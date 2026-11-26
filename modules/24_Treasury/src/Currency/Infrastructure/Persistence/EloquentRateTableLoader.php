<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Models\ExchangeRate as ExchangeRateRecord;
use Illuminate\Database\Eloquent\Builder;
use Modules\Treasury\Currency\Domain\Contracts\RateTableLoader;
use Modules\Treasury\Currency\Domain\Entities\ExchangeRateTable;
use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * Loads the rates a company sees: the official ones (no tenant) and its own, never another company's. Days are compared with
 * `whereDate` so that SQLite (text dates) and PostgreSQL (date columns) select the same rows. The tenant is filtered here, not
 * taken from the request context, so the scheduler and the queue see the same rates as the API.
 */
final class EloquentRateTableLoader implements RateTableLoader
{
    public function load(array $currencies, string $from, string $to, ?string $tenantId, array $types): ExchangeRateTable
    {
        $currencies = array_values(array_unique(array_map('strtoupper', $currencies)));
        $records = ExchangeRateRecord::query()
            ->withoutGlobalScopes()
            ->whereIn('source', $currencies)
            ->whereIn('target', $currencies)
            ->whereIn('rate_type', array_map(static fn (RateType $type): string => $type->value, $types))
            ->whereNull('superseded_by')
            ->whereDate('effective_date', '>=', $from)
            ->whereDate('effective_date', '<=', $to)
            ->where(static function (Builder $query) use ($tenantId): void {
                $query->whereNull('tenant_id');

                if ($tenantId !== null) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->get();

        $table = new ExchangeRateTable;

        foreach ($records as $record) {
            $rate = ExchangeRateMapper::toDomain($record);

            if ($rate !== null) {
                $table->add($rate);
            }
        }

        return $table;
    }
}
