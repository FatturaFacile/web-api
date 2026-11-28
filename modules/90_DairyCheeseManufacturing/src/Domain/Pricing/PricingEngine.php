<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Pricing;

/**
 * Quality pricing of a month of milk. Amount = base price × kg, plus the tier adjustments per kg (bonus when positive, penalty when
 * negative; the first tier of a component whose [from, to) range holds the measured value applies), plus the cheese-milk supplement
 * (Swiss Zulage für verkäste Milch) and the DOP premium on the kilograms processed into cheese. VAT is added on the gross.
 */
final class PricingEngine
{
    /**
     * @param  array{base_price_per_kg: float|string, cheese_milk_supplement_per_kg: float|string, dop_premium_per_kg: float|string, tiers: list<array{component: string, from_value: float|string|null, to_value: float|string|null, adjustment_per_kg: float|string}>}  $grid
     */
    public function price(array $grid, PricingInput $input, float $vatRatePercent): PricingResult
    {
        $lines = [];
        $base = $this->money($input->totalKg * (float) $grid['base_price_per_kg']);
        $lines[] = ['component' => 'base', 'label_key' => 'DAIRY.SETTLEMENT.LINE.BASE', 'quantity_kg' => $this->kg($input->totalKg), 'rate' => $this->rate((float) $grid['base_price_per_kg']), 'amount' => $base];

        $bonus = 0.0;
        $penalty = 0.0;
        $applied = [];

        foreach ($grid['tiers'] as $tier) {
            $component = (string) $tier['component'];
            $value = $input->valueOf($component);

            if ($value === null || isset($applied[$component]) || ! $this->matches($tier, $value)) {
                continue;
            }

            $applied[$component] = true;
            $rate = (float) $tier['adjustment_per_kg'];
            $amount = $this->money($input->totalKg * $rate);
            $lines[] = ['component' => $component, 'label_key' => 'DAIRY.SETTLEMENT.LINE.'.strtoupper($component), 'quantity_kg' => $this->kg($input->totalKg), 'rate' => $this->rate($rate), 'amount' => $amount];

            if ($amount >= 0) {
                $bonus += $amount;
            } else {
                $penalty += -$amount;
            }
        }

        $supplement = 0.0;

        foreach (['cheese_milk_supplement' => (float) $grid['cheese_milk_supplement_per_kg'], 'dop_premium' => (float) $grid['dop_premium_per_kg']] as $component => $rate) {
            if ($rate === 0.0 || $input->cheeseMilkKg <= 0) {
                continue;
            }

            $amount = $this->money($input->cheeseMilkKg * $rate);
            $supplement += $amount;
            $lines[] = ['component' => $component, 'label_key' => 'DAIRY.SETTLEMENT.LINE.'.strtoupper($component), 'quantity_kg' => $this->kg($input->cheeseMilkKg), 'rate' => $this->rate($rate), 'amount' => $amount];
        }

        $gross = $this->money($base + $bonus + $supplement - $penalty);
        $vat = $this->money($gross * $vatRatePercent / 100);

        return new PricingResult($lines, $base, $this->money($bonus), $this->money($supplement), $this->money($penalty), $gross, $vat, $this->money($gross + $vat));
    }

    /**
     * @param  array{from_value: float|string|null, to_value: float|string|null}  $tier
     */
    private function matches(array $tier, float $value): bool
    {
        $from = $tier['from_value'] === null ? null : (float) $tier['from_value'];
        $to = $tier['to_value'] === null ? null : (float) $tier['to_value'];

        return ($from === null || $value >= $from) && ($to === null || $value < $to);
    }

    private function money(float $amount): float
    {
        return round($amount, 2);
    }

    private function kg(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function rate(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
