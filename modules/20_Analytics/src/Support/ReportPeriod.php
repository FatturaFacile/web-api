<?php

declare(strict_types=1);

namespace Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting period and the period it is compared with. The comparison is never invented: it is either the period of
 * the same length right before, the same dates one year earlier, or absent.
 */
final readonly class ReportPeriod
{
    public const string ComparePreviousPeriod = 'previous_period';

    public const string ComparePreviousYear = 'previous_year';

    public const string CompareNone = 'none';

    /**
     * Longest period a single request may cover, in days.
     */
    public const int MaxDays = 366;

    /**
     * Create the period.
     *
     * @throws InvalidArgumentException when the dates are inverted, the period is too long or the comparison unknown
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $compare = self::CompareNone,
    ) {
        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('The start of the period is after its end.');
        }

        if ($from->diffInDays($to) > self::MaxDays) {
            throw new InvalidArgumentException('The period cannot be longer than '.self::MaxDays.' days.');
        }

        if (! in_array($compare, [self::ComparePreviousPeriod, self::ComparePreviousYear, self::CompareNone], true)) {
            throw new InvalidArgumentException("Unknown comparison [{$compare}].");
        }
    }

    /**
     * The period compared with, or null when no comparison was asked for.
     */
    public function comparison(): ?self
    {
        return match ($this->compare) {
            self::ComparePreviousYear => new self($this->from->subYearNoOverflow(), $this->to->subYearNoOverflow()),
            self::ComparePreviousPeriod => new self(
                $this->from->subDays((int) $this->from->diffInDays($this->to) + 1),
                $this->from->subDay(),
            ),
            default => null,
        };
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
