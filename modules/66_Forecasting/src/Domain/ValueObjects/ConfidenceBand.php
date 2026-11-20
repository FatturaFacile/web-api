<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A monetary or quantity estimate as three quantiles: p10 pessimistic, p50 expected, p90 optimistic.
 */
final readonly class ConfidenceBand
{
    public function __construct(
        public float $p10,
        public float $p50,
        public float $p90,
    ) {
        if ($p10 > $p50 + 1e-9 || $p50 > $p90 + 1e-9) {
            throw new InvalidArgumentException('A confidence band needs p10 <= p50 <= p90.');
        }
    }

    /**
     * Builds a band from three values in any order.
     */
    public static function sorted(float $a, float $b, float $c): self
    {
        $values = [$a, $b, $c];
        sort($values);

        return new self($values[0], $values[1], $values[2]);
    }

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    public static function point(float $value): self
    {
        return new self($value, $value, $value);
    }

    public function plus(self $other): self
    {
        return new self($this->p10 + $other->p10, $this->p50 + $other->p50, $this->p90 + $other->p90);
    }

    public function minus(self $other): self
    {
        return self::sorted($this->p10 - $other->p90, $this->p50 - $other->p50, $this->p90 - $other->p10);
    }

    public function spread(): float
    {
        return $this->p90 - $this->p10;
    }

    /**
     * @return array{p10: float, p50: float, p90: float}
     */
    public function toArray(): array
    {
        return ['p10' => $this->p10, 'p50' => $this->p50, 'p90' => $this->p90];
    }
}
