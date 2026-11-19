<?php

declare(strict_types=1);

namespace App\Services\Sales\Cpq;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Validation\ValidationException;

/**
 * Discount and margin what-if on a quote, never saved: the global discount that reaches a target margin percentage or a
 * target total price, or just the effect of a given discount percentage, next to the current figures.
 */
final readonly class QuoteMarginSimulator
{
    public const string ModeDiscount = 'discount_percent';

    public const string ModeTargetMargin = 'target_margin_percent';

    public const string ModeTargetPrice = 'target_total_price';

    public function __construct(private MarginCalculator $calculator) {}

    /**
     * @param  array{mode: string, value: mixed, surcharge_amount?: mixed, setup_cost?: mixed, cost_exchange_rate?: mixed}  $input
     * @return array{mode: string, current: array<string, string|null>, simulated: array<string, string|null>, global_discount_percent: string, achievable: bool}
     */
    public function simulate(Quote $quote, array $input): array
    {
        $lines = $this->lines($quote, $input['cost_exchange_rate'] ?? null);
        $header = ['surcharge_amount' => $input['surcharge_amount'] ?? '0', 'setup_cost' => $input['setup_cost'] ?? '0'];
        $base = $this->calculator->calculate($lines, $header)['totals'];
        $value = (string) $input['value'];
        $discount = $this->discountFor($input['mode'], $value, $base);
        $simulated = $this->calculator->calculate($lines, $header + ['global_discount_percent' => $discount])['totals'];

        return [
            'mode' => $input['mode'],
            'current' => $base,
            'simulated' => $simulated,
            'global_discount_percent' => $discount,
            'achievable' => $this->achievable($input['mode'], $value, $simulated, $discount),
        ];
    }

    /**
     * The quote lines the customer pays for: required lines and the optional ones selected.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(Quote $quote, mixed $costExchangeRate = null): array
    {
        return $quote->items
            ->filter(static fn (QuoteItem $item): bool => ! $item->is_optional || $item->is_selected)
            ->map(static fn (QuoteItem $item): array => [
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount_percentage' => (string) ($item->discount_percentage ?? '0'),
                'unit_cost' => (string) ($item->cost_price ?? '0'),
                'labor_cost' => (string) ($item->labor_cost ?? '0'),
                'cost_exchange_rate' => $costExchangeRate,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string|null>  $base
     */
    private function discountFor(string $mode, string $value, array $base): string
    {
        $surcharge = (string) $base['surcharge_amount'];
        $lineNet = bcsub((string) $base['net_total'], $surcharge, 6);
        $cost = (string) $base['cost_total'];

        return match ($mode) {
            self::ModeDiscount => $this->clamp($value),
            self::ModeTargetMargin => $this->discountForNet($lineNet, $this->minZero(bcsub($this->netForMargin($cost, $value), $surcharge, 6))),
            self::ModeTargetPrice => $this->discountForNet($lineNet, $this->minZero(bcsub($value, $surcharge, 6))),
            default => throw ValidationException::withMessages(['mode' => __('sales.cpq.invalid_simulation_mode')]),
        };
    }

    private function minZero(string $value): string
    {
        return bccomp($value, '0', 6) < 0 ? '0' : $value;
    }

    private function netForMargin(string $cost, string $marginPercent): string
    {
        $divisor = bcsub('1', bcdiv($marginPercent, '100', 6), 6);

        return bccomp($divisor, '0', 6) <= 0 ? '0' : bcdiv($cost, $divisor, 6);
    }

    /**
     * Global discount percentage that brings the net price down to the wanted one; a wanted price at or above the
     * current one needs no discount.
     */
    private function discountForNet(string $currentNet, string $wantedNet): string
    {
        if (bccomp($currentNet, '0', 6) <= 0 || bccomp($wantedNet, $currentNet, 6) >= 0) {
            return '0.00';
        }

        return $this->clamp(bcmul(bcsub('1', bcdiv($wantedNet, $currentNet, 8), 8), '100', 8));
    }

    private function clamp(string $percent): string
    {
        if (bccomp($percent, '0', 6) < 0) {
            return '0.00';
        }

        return bccomp($percent, '100', 6) > 0 ? '100.00' : $this->calculator->round2($percent);
    }

    /**
     * Whether the simulation reaches what was asked (rounding of one cent or 0.01 points allowed).
     *
     * @param  array<string, string|null>  $simulated
     */
    private function achievable(string $mode, string $value, array $simulated, string $discount): bool
    {
        return match ($mode) {
            self::ModeTargetMargin => $simulated['margin_percent'] !== null && bccomp((string) $simulated['margin_percent'], bcsub($value, '0.01', 2), 2) >= 0,
            self::ModeTargetPrice => bccomp((string) $simulated['net_total'], bcadd($value, '0.01', 2), 2) <= 0,
            default => bccomp($discount, '100', 2) < 0,
        };
    }
}
