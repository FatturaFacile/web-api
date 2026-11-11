<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use InvalidArgumentException;

/**
 * An aggregate of a dataset. `sql` is an aggregate expression declared in code.
 */
final readonly class Measure
{
    public const string UnitCurrency = 'currency';

    public const string UnitCount = 'count';

    public const string UnitQuantity = 'quantity';

    public const string UnitPercent = 'percent';

    /**
     * @param  string  $sql  aggregate expression, written by the developer
     * @param  string|null  $join  alias of the declared join the expression needs
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $unit,
        public string $sql,
        public ?string $join = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid measure key [{$key}].");
        }

        if (! in_array($unit, [self::UnitCurrency, self::UnitCount, self::UnitQuantity, self::UnitPercent], true)) {
            throw new InvalidArgumentException("Invalid measure unit [{$unit}].");
        }
    }

    /**
     * Decimals of the figure when it is returned.
     */
    public function scale(): int
    {
        return match ($this->unit) {
            self::UnitCount => 0,
            self::UnitQuantity => 4,
            default => 2,
        };
    }

    /**
     * @return array{key: string, label: string, unit: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'unit' => $this->unit];
    }
}
