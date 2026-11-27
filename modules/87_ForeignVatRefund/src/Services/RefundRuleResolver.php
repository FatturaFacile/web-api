<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;

/**
 * Reads the reciprocity and deductibility matrix: company rows override the global (seeded) rows cell by cell. A country that is the
 * home country of the company has no refund procedure (domestic VAT goes through the ordinary VAT settlement).
 */
final class RefundRuleResolver
{
    public function homeCountry(string $tenantId): string
    {
        $country = Tenant::query()->whereKey($tenantId)->value('country');

        return is_string($country) && $country !== '' ? strtoupper($country) : 'IT';
    }

    /**
     * The rule of one cell, null when the home country has no procedure for the country or the category.
     */
    public function find(string $tenantId, string $homeCountry, string $refundCountry, int $category): ?CountryVatRefundRule
    {
        if (strtoupper($homeCountry) === strtoupper($refundCountry)) {
            return null;
        }

        return $this->query($tenantId, $homeCountry, $refundCountry)
            ->where('expense_category_code', $category)
            ->get()
            ->sortBy(fn (CountryVatRefundRule $rule): int => $rule->tenant_id === null ? 1 : 0)
            ->first();
    }

    /**
     * One rule per category (company overrides win), ordered by category.
     *
     * @return Collection<int, CountryVatRefundRule>
     */
    public function matrix(string $tenantId, string $homeCountry, string $refundCountry): Collection
    {
        if (strtoupper($homeCountry) === strtoupper($refundCountry)) {
            return new Collection;
        }

        return $this->query($tenantId, $homeCountry, $refundCountry)->get()
            ->sortBy(fn (CountryVatRefundRule $rule): int => $rule->tenant_id === null ? 1 : 0)
            ->unique(fn (CountryVatRefundRule $rule): string => $rule->expense_category_code.'|'.($rule->sub_code ?? ''))
            ->sortBy('expense_category_code')
            ->values();
    }

    /**
     * The procedure data of a country (regime, currency, thresholds, deadline, requirements): the same for every category.
     */
    public function procedure(string $tenantId, string $homeCountry, string $refundCountry): ?CountryVatRefundRule
    {
        return $this->matrix($tenantId, $homeCountry, $refundCountry)->first();
    }

    /**
     * Whether the home country can claim VAT back from the country at all.
     */
    public function hasReciprocity(string $tenantId, string $homeCountry, string $refundCountry): bool
    {
        return $this->matrix($tenantId, $homeCountry, $refundCountry)->contains(fn (CountryVatRefundRule $rule): bool => $rule->has_reciprocity);
    }

    /**
     * Countries the home country has at least one procedure for.
     *
     * @return list<string>
     */
    public function refundCountries(string $tenantId, string $homeCountry): array
    {
        return CountryVatRefundRule::withoutGlobalScopes()
            ->where('home_country_code', strtoupper($homeCountry))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->distinct()
            ->orderBy('refund_country_code')
            ->pluck('refund_country_code')
            ->all();
    }

    /**
     * @return Builder<CountryVatRefundRule>
     */
    private function query(string $tenantId, string $homeCountry, string $refundCountry)
    {
        return CountryVatRefundRule::withoutGlobalScopes()
            ->where('home_country_code', strtoupper($homeCountry))
            ->where('refund_country_code', strtoupper($refundCountry))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }
}
