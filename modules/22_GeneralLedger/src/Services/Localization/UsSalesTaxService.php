<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\UsTaxJurisdiction;
use Modules\GeneralLedger\Models\UsTaxNexus;
use Modules\GeneralLedger\Models\UsTaxRate;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * United States sales tax: collects tax only in states where the company has nexus on the sale date, and adds the rates
 * in force of every jurisdiction that covers the destination (state, county, city, special district by ZIP prefix).
 */
final class UsSalesTaxService
{
    /**
     * @return array{state: string, date: string, has_nexus: bool, taxable_amount: string, shipping_amount: string, lines: list<array{jurisdiction_id: string, code: string, name: string, level: string, rate_percent: string, tax: string}>, rate_percent: string, tax_total: string, reason: string|null}
     */
    public function calculate(string $tenantId, string $state, string $date, string $taxableAmount, string $shippingAmount = '0', ?string $county = null, ?string $city = null, ?string $zip = null): array
    {
        $state = strtoupper($state);
        $day = Carbon::parse($date)->toDateString();
        $result = [
            'state' => $state,
            'date' => $day,
            'has_nexus' => $this->hasNexus($tenantId, $state, $day),
            'taxable_amount' => Decimal::round2($taxableAmount),
            'shipping_amount' => Decimal::round2($shippingAmount),
            'lines' => [],
            'rate_percent' => '0.0000',
            'tax_total' => '0.00',
            'reason' => null,
        ];

        if (! $result['has_nexus']) {
            $result['reason'] = 'no_nexus';

            return $result;
        }

        $total = '0';
        $rate = '0';

        foreach ($this->jurisdictionsFor($tenantId, $state, $county, $city, $zip) as $jurisdiction) {
            $applicable = $this->rateOn($jurisdiction, $day);

            if ($applicable === null) {
                continue;
            }

            $base = $applicable->applies_to_shipping ? Decimal::add($taxableAmount, $shippingAmount) : $taxableAmount;
            $tax = Decimal::round2(Decimal::div(Decimal::mul($base, (string) $applicable->rate_percent), 100));
            $total = Decimal::add($total, $tax);
            $rate = Decimal::add($rate, (string) $applicable->rate_percent);
            $result['lines'][] = [
                'jurisdiction_id' => $jurisdiction->id,
                'code' => $jurisdiction->code,
                'name' => $jurisdiction->name,
                'level' => $jurisdiction->level,
                'rate_percent' => number_format((float) $applicable->rate_percent, 4, '.', ''),
                'tax' => $tax,
            ];
        }

        $result['rate_percent'] = number_format((float) $rate, 4, '.', '');
        $result['tax_total'] = Decimal::round2($total);
        $result['reason'] = $result['lines'] === [] ? 'no_rate' : null;

        return $result;
    }

    public function hasNexus(string $tenantId, string $state, string $date): bool
    {
        return UsTaxNexus::query()
            ->where('tenant_id', $tenantId)
            ->where('state', strtoupper($state))
            ->whereDate('established_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>=', $date))
            ->exists();
    }

    /**
     * @return Collection<int, UsTaxJurisdiction>
     */
    private function jurisdictionsFor(string $tenantId, string $state, ?string $county, ?string $city, ?string $zip): Collection
    {
        return UsTaxJurisdiction::query()
            ->where('tenant_id', $tenantId)
            ->where('state', $state)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (UsTaxJurisdiction $jurisdiction): bool => match ($jurisdiction->level) {
                'state' => true,
                'county' => $county !== null && strcasecmp((string) $jurisdiction->county, $county) === 0,
                'city' => $city !== null && strcasecmp((string) $jurisdiction->city, $city) === 0,
                default => $zip !== null && $jurisdiction->zip_prefix !== null && str_starts_with($zip, $jurisdiction->zip_prefix),
            })
            ->values();
    }

    private function rateOn(UsTaxJurisdiction $jurisdiction, string $day): ?UsTaxRate
    {
        return $jurisdiction->rates()
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();
    }
}
