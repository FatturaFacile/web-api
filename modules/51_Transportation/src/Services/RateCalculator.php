<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Support\Carbon;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsTariffBand;
use Modules\Transportation\Models\TmsTariffTable;
use RoundingMode;

/**
 * Prices a shipment with the tariff tables of a carrier. Among the active tables valid on the date (newest first) the first
 * one with a band that fits wins; inside a table the most specific band wins (origin and destination zone, then destination
 * zone, then origin zone, then any zone). A band covers a weight and a distance range with its lower bound included and its
 * upper bound excluded. The amount is the fixed amount plus the price per kg and per km the rate basis allows, raised to the
 * minimum charge of the table, plus the fuel surcharge. All amounts are exact decimals rounded half away from zero to cents.
 */
final class RateCalculator
{
    /**
     * @return array{carrier_id: string, carrier_name: string, tariff_table_id: string, tariff_band_id: string, currency: string, fixed_amount: string, weight_amount: string, distance_amount: string, minimum_applied: bool, base_amount: string, fuel_surcharge: string, total_amount: string, rank: int|null}|null
     */
    public function rate(TmsCarrier $carrier, string $weightKg, ?string $distanceKm, ?string $originZone, ?string $destinationZone, Carbon|string $date, ?string $currency = null): ?array
    {
        $day = Carbon::parse($date)->toDateString();
        $tables = TmsTariffTable::query()
            ->where('tenant_id', $carrier->tenant_id)
            ->where('carrier_id', $carrier->id)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $day)
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->when($currency !== null, static fn ($query) => $query->where('currency', $currency))
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->get();

        foreach ($tables as $table) {
            $band = $this->bestBand($table, $weightKg, $distanceKm, $originZone, $destinationZone);

            if ($band !== null) {
                return $this->price($carrier, $table, $band, $weightKg, $distanceKm);
            }
        }

        return null;
    }

    /**
     * The offer of every active carrier that can price the shipment, cheapest first (ties by carrier name).
     *
     * @return list<array{carrier_id: string, carrier_name: string, tariff_table_id: string, tariff_band_id: string, currency: string, fixed_amount: string, weight_amount: string, distance_amount: string, minimum_applied: bool, base_amount: string, fuel_surcharge: string, total_amount: string, rank: int|null}>
     */
    public function compare(string $tenantId, string $weightKg, ?string $distanceKm, ?string $originZone, ?string $destinationZone, Carbon|string $date, string $currency): array
    {
        $offers = [];

        foreach (TmsCarrier::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->get() as $carrier) {
            $offer = $this->rate($carrier, $weightKg, $distanceKm, $originZone, $destinationZone, $date, $currency);

            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        usort($offers, static fn (array $left, array $right): int => bccomp($left['total_amount'], $right['total_amount'], 2) ?: strcmp($left['carrier_name'], $right['carrier_name']));

        foreach ($offers as $index => $offer) {
            $offers[$index]['rank'] = $index + 1;
        }

        return $offers;
    }

    private function bestBand(TmsTariffTable $table, string $weightKg, ?string $distanceKm, ?string $originZone, ?string $destinationZone): ?TmsTariffBand
    {
        $needsDistance = in_array($table->rate_basis, ['distance', 'weight_distance'], true);

        if ($needsDistance && $distanceKm === null) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach (TmsTariffBand::query()->where('tenant_id', $table->tenant_id)->where('tariff_table_id', $table->id)->orderBy('weight_from_kg')->orderBy('id')->get() as $band) {
            if ($band->origin_zone !== null && $band->origin_zone !== $originZone) {
                continue;
            }

            if ($band->destination_zone !== null && $band->destination_zone !== $destinationZone) {
                continue;
            }

            if (! $this->within($weightKg, (string) $band->weight_from_kg, $band->weight_to_kg !== null ? (string) $band->weight_to_kg : null, 3)) {
                continue;
            }

            if ($distanceKm !== null && ! $this->within($distanceKm, (string) $band->distance_from_km, $band->distance_to_km !== null ? (string) $band->distance_to_km : null, 2)) {
                continue;
            }

            $score = ($band->origin_zone !== null ? 1 : 0) + ($band->destination_zone !== null ? 2 : 0);

            if ($score > $bestScore) {
                $best = $band;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Whether from <= value < to (open ended without upper bound).
     */
    private function within(string $value, string $from, ?string $to, int $scale): bool
    {
        return bccomp($value, $from, $scale) >= 0 && ($to === null || bccomp($value, $to, $scale) < 0);
    }

    /**
     * @return array{carrier_id: string, carrier_name: string, tariff_table_id: string, tariff_band_id: string, currency: string, fixed_amount: string, weight_amount: string, distance_amount: string, minimum_applied: bool, base_amount: string, fuel_surcharge: string, total_amount: string, rank: int|null}
     */
    private function price(TmsCarrier $carrier, TmsTariffTable $table, TmsTariffBand $band, string $weightKg, ?string $distanceKm): array
    {
        $basis = $table->rate_basis;
        $fixed = $this->cents((string) $band->fixed_amount);
        $weightAmount = in_array($basis, ['weight', 'weight_distance'], true) ? $this->cents(bcmul((string) $band->price_per_kg, $weightKg, 8)) : '0.00';
        $distanceAmount = in_array($basis, ['distance', 'weight_distance'], true) && $distanceKm !== null ? $this->cents(bcmul((string) $band->price_per_km, $distanceKm, 8)) : '0.00';

        $base = bcadd(bcadd($fixed, $weightAmount, 2), $distanceAmount, 2);
        $minimum = $this->cents((string) $table->minimum_charge);
        $minimumApplied = bccomp($base, $minimum, 2) < 0;

        if ($minimumApplied) {
            $base = $minimum;
        }

        $fuel = $this->cents(bcdiv(bcmul($base, (string) $table->fuel_surcharge_percent, 8), '100', 8));

        return [
            'carrier_id' => $carrier->id,
            'carrier_name' => $carrier->name,
            'tariff_table_id' => $table->id,
            'tariff_band_id' => $band->id,
            'currency' => $table->currency,
            'fixed_amount' => $fixed,
            'weight_amount' => $weightAmount,
            'distance_amount' => $distanceAmount,
            'minimum_applied' => $minimumApplied,
            'base_amount' => $base,
            'fuel_surcharge' => $fuel,
            'total_amount' => bcadd($base, $fuel, 2),
            'rank' => null,
        ];
    }

    private function cents(string $value): string
    {
        return bcround($value, 2, RoundingMode::HalfAwayFromZero);
    }
}
