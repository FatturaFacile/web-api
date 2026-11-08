<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A depreciation period parsed from its code: "2026-03" (monthly), "2026-Q1" (quarterly) or "2026" (yearly).
 */
final readonly class DepreciationPeriod
{
    public const array Types = ['monthly', 'quarterly', 'yearly'];

    public function __construct(
        public string $code,
        public string $type,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $months,
    ) {}

    /**
     * Parses a period code of the given type.
     *
     * @throws InvalidArgumentException when the code does not match the type
     */
    public static function fromCode(string $code, string $type): self
    {
        $parts = [];
        $matched = match ($type) {
            'monthly' => preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $code, $parts),
            'quarterly' => preg_match('/^(\d{4})-Q([1-4])$/', $code, $parts),
            'yearly' => preg_match('/^(\d{4})$/', $code, $parts),
            default => 0,
        };

        if ($matched !== 1) {
            throw new InvalidArgumentException("Invalid period code [{$code}] for period type [{$type}].");
        }

        $year = (int) $parts[1];

        [$start, $months] = match ($type) {
            'monthly' => [CarbonImmutable::create($year, (int) $parts[2], 1), 1],
            'quarterly' => [CarbonImmutable::create($year, ((int) $parts[2] - 1) * 3 + 1, 1), 3],
            default => [CarbonImmutable::create($year, 1, 1), 12],
        };

        $start = $start->startOfDay();

        return new self($code, $type, $start, $start->addMonthsNoOverflow($months)->subDay(), $months);
    }

    /**
     * The period of the given type that contains a date.
     */
    public static function containing(CarbonImmutable $date, string $type): self
    {
        $code = match ($type) {
            'monthly' => $date->format('Y-m'),
            'quarterly' => $date->format('Y').'-Q'.(int) ceil($date->month / 3),
            default => $date->format('Y'),
        };

        return self::fromCode($code, $type);
    }

    /**
     * The following period of the same type.
     */
    public function next(): self
    {
        return self::containing($this->end->addDay(), $this->type);
    }

    /**
     * Whether the period ends before a date.
     */
    public function endsBefore(CarbonImmutable $date): bool
    {
        return $this->end->lt($date->startOfDay());
    }

    /**
     * Whether a date falls inside the period.
     */
    public function contains(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->start, $this->end);
    }
}
