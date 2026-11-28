<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Modules\DairyCheeseManufacturing\Domain\Weight\WeightLossCurveInterpolator;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\WeightLossCurve;

/**
 * Dual inventory of a wheel: besides counting it, the weight is the last weighing or, without one, the theoretical weight of
 * the physiological loss curve of its cheese type at its age. The wheel count never changes with the weight.
 */
final class WheelMetrics
{
    /** @var array<string, list<array{age_months: float|int|string, loss_pct: float|int|string}>> */
    private array $curves = [];

    /**
     * @return list<array{age_months: float|int|string, loss_pct: float|int|string}>
     */
    public function pointsFor(string $cheeseType): array
    {
        if (isset($this->curves[$cheeseType])) {
            return $this->curves[$cheeseType];
        }

        $curve = WeightLossCurve::query()->where('cheese_type', $cheeseType)->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')->first()
            ?? WeightLossCurve::query()->where('cheese_type', 'generic')->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')->first();

        /** @var list<array{age_months: float|int|string, loss_pct: float|int|string}> $points */
        $points = $curve === null ? [] : array_values((array) $curve->points);

        return $this->curves[$cheeseType] = $points;
    }

    public function flush(): void
    {
        $this->curves = [];
    }

    public function ageMonths(CheeseAgingWheel $wheel, ?CarbonImmutable $asOf = null): float
    {
        $production = CarbonImmutable::parse($wheel->batch->production_date);
        $end = $wheel->sold_at !== null ? CarbonImmutable::parse($wheel->sold_at) : ($asOf ?? CarbonImmutable::now());
        $days = max(0, $production->startOfDay()->diffInDays($end->startOfDay(), false));

        return round($days / (float) config('dairy_cheese.days_per_month', 30.4375), 2);
    }

    /**
     * @return array{age_months: float, initial_kg: float, theoretical_kg: float, theoretical_loss_pct: float, current_kg: float, loss_pct: float}
     */
    public function of(CheeseAgingWheel $wheel, ?CarbonImmutable $asOf = null): array
    {
        $age = $this->ageMonths($wheel, $asOf);
        $initial = (float) $wheel->initial_weight_kg;
        $points = $this->pointsFor((string) $wheel->batch->cheese_type);
        $lossPct = WeightLossCurveInterpolator::lossPct($points, $age);
        $theoretical = WeightLossCurveInterpolator::theoreticalWeight($initial, $points, $age);
        $current = $wheel->measured_weight_kg !== null ? (float) $wheel->measured_weight_kg : $theoretical;

        return [
            'age_months' => $age,
            'initial_kg' => round($initial, 3),
            'theoretical_kg' => $theoretical,
            'theoretical_loss_pct' => $lossPct,
            'current_kg' => round($current, 3),
            'loss_pct' => $initial > 0 ? round(($initial - $current) / $initial * 100, 3) : 0.0,
        ];
    }
}
