<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Models\CompetitorBenchmark;

/**
 * The competitor award history: filtering by contracting authority and CPV, and the discount and score statistics the offer
 * simulator uses as market intelligence.
 */
final class BenchmarkService
{
    /**
     * @return Builder<CompetitorBenchmark>
     */
    public function filtered(?string $search, ?string $authority, ?string $cpv, ?string $jurisdiction, bool $winnersOnly): Builder
    {
        return CompetitorBenchmark::query()
            ->when($search !== null && $search !== '', function (Builder $query) use ($search): void {
                $term = '%'.mb_strtolower((string) $search).'%';
                $query->where(fn (Builder $inner) => $inner->whereRaw('LOWER(competitor_name) LIKE ?', [$term])->orWhereRaw('LOWER(contracting_authority_name) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(cpv_code, \'\')) LIKE ?', [$term]));
            })
            ->when($authority !== null && $authority !== '', fn (Builder $query) => $query->whereRaw('LOWER(contracting_authority_name) = ?', [mb_strtolower((string) $authority)]))
            ->when($cpv !== null && $cpv !== '', fn (Builder $query) => $query->where('cpv_code', 'like', substr((string) $cpv, 0, 8).'%'))
            ->when($jurisdiction !== null && $jurisdiction !== '', fn (Builder $query) => $query->where('jurisdiction', $jurisdiction))
            ->when($winnersOnly, fn (Builder $query) => $query->where('is_winner', true));
    }

    /**
     * @return array{count: int, average_discount: string|null, median_discount: string|null, max_discount: string|null, average_technical_score: string|null, average_economic_score: string|null, average_total_score: string|null}
     */
    public function statistics(?string $authority, ?string $cpv): array
    {
        $rows = $this->filtered(null, $authority, $cpv, null, false)->get();
        $discounts = $rows->pluck('offered_discount_percentage')->filter(fn ($value): bool => $value !== null)->values()->all();
        $maximum = $discounts === [] ? null : array_reduce($discounts, static fn (string $carry, string $value): string => Dec::max($carry, $value), '0');

        $mean = static fn (string $column): ?string => ($values = $rows->pluck($column)->filter(fn ($value): bool => $value !== null)->values()->all()) === [] ? null : Dec::round(Dec::mean($values), 2);

        return [
            'count' => $rows->count(),
            'average_discount' => $discounts === [] ? null : Dec::percent(Dec::mean($discounts)),
            'median_discount' => $discounts === [] ? null : Dec::percent(Dec::median($discounts)),
            'max_discount' => $maximum === null ? null : Dec::percent($maximum),
            'average_technical_score' => $mean('technical_score'),
            'average_economic_score' => $mean('economic_score'),
            'average_total_score' => $mean('total_score'),
        ];
    }

    /**
     * The competitors' discounts of the same contracting authority, for the offer simulator.
     *
     * @return list<string>
     */
    public function discountsFor(string $authority, ?string $cpv): array
    {
        return $this->filtered(null, $authority, $cpv, null, false)->whereNotNull('offered_discount_percentage')->pluck('offered_discount_percentage')->map(fn ($value): string => (string) $value)->values()->all();
    }
}
