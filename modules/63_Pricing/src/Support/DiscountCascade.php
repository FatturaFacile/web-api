<?php

declare(strict_types=1);

namespace Modules\Pricing\Support;

use InvalidArgumentException;

/**
 * A discount cascade written the commercial way: "20+5+2". MULTIPLICATIVE chains each step on the price left by the
 * previous one (100 - 20% - 5% = 76); ADDITIVE sums the steps into one percentage (100 - (20% + 5%) = 75).
 */
final readonly class DiscountCascade
{
    public const string Multiplicative = 'MULTIPLICATIVE';

    public const string Additive = 'ADDITIVE';

    /**
     * @param  list<string>  $steps  percentages as decimal strings
     */
    private function __construct(public array $steps, public string $mode) {}

    public static function parse(?string $notation, string $mode = self::Multiplicative): self
    {
        if (! in_array($mode, [self::Multiplicative, self::Additive], true)) {
            throw new InvalidArgumentException("Unknown discount mode {$mode}.");
        }

        $steps = [];

        foreach (explode('+', str_replace([' ', ','], ['', '.'], (string) $notation)) as $part) {
            if ($part === '') {
                continue;
            }

            if (! is_numeric($part) || bccomp($part, '0', 6) < 0 || bccomp($part, '100', 6) > 0) {
                throw new InvalidArgumentException("Invalid discount step \"{$part}\": expected a percentage between 0 and 100.");
            }

            $steps[] = bcadd($part, '0', 4);
        }

        return new self($steps, $mode);
    }

    public static function isValid(?string $notation): bool
    {
        try {
            self::parse($notation);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * The single percentage the cascade is worth (0-100, 4 decimals).
     */
    public function effectivePercent(): string
    {
        if ($this->mode === self::Additive) {
            $sum = array_reduce($this->steps, static fn (string $total, string $step): string => bcadd($total, $step, 6), '0');

            return bccomp($sum, '100', 6) > 0 ? '100.0000' : bcadd($sum, '0', 4);
        }

        $factor = array_reduce($this->steps, static fn (string $f, string $step): string => bcmul($f, bcsub('1', bcdiv($step, '100', 8), 8), 8), '1');

        return bcmul(bcsub('1', $factor, 8), '100', 4);
    }

    /**
     * The price after the cascade, 4 decimals.
     *
     * @return array{price: string, steps: list<array{percent: string, price: string}>}
     */
    public function apply(string $price): array
    {
        $trace = [];

        if ($this->mode === self::Additive) {
            return ['price' => bcmul($price, bcsub('1', bcdiv($this->effectivePercent(), '100', 8), 8), 4), 'steps' => $trace];
        }

        $current = bcadd($price, '0', 8);

        foreach ($this->steps as $step) {
            $current = bcmul($current, bcsub('1', bcdiv($step, '100', 8), 8), 8);
            $trace[] = ['percent' => $step, 'price' => bcadd($current, '0', 4)];
        }

        return ['price' => bcadd($current, '0', 4), 'steps' => $trace];
    }

    public function notation(): string
    {
        return implode('+', array_map(static fn (string $step): string => rtrim(rtrim($step, '0'), '.'), $this->steps));
    }
}
