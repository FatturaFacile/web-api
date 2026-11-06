<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Exceptions\VatSettlementException;

/**
 * Calendar period of a VAT settlement.
 */
final readonly class VatPeriod
{
    public const string MONTHLY = 'monthly';

    public const string QUARTERLY = 'quarterly';

    public const string SEMIANNUAL = 'semiannual';

    private function __construct(
        public string $type,
        public int $year,
        public int $number,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function of(string $type, int $year, int $number): self
    {
        $months = match ($type) {
            self::MONTHLY => 1,
            self::QUARTERLY => 3,
            self::SEMIANNUAL => 6,
            default => throw VatSettlementException::invalid("Unsupported VAT period type [{$type}]."),
        };

        if ($number < 1 || $number > (int) (12 / $months)) {
            throw VatSettlementException::invalid("VAT period {$number} does not exist for a {$type} return.");
        }

        $from = CarbonImmutable::create($year, (($number - 1) * $months) + 1, 1)->startOfDay();

        return new self($type, $year, $number, $from, $from->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay());
    }

    /**
     * Recognizes the calendar month, quarter or half year spanned by two days.
     */
    public static function fromDates(CarbonImmutable $from, CarbonImmutable $to): self
    {
        foreach ([self::MONTHLY => 1, self::QUARTERLY => 3, self::SEMIANNUAL => 6] as $type => $months) {
            $number = (int) ceil($from->month / $months);
            $candidate = self::of($type, $from->year, $number);

            if ($candidate->from->isSameDay($from) && $candidate->to->isSameDay($to)) {
                return $candidate;
            }
        }

        throw VatSettlementException::invalid('The period must be a whole calendar month, quarter or half year.');
    }

    /**
     * The canonical label stored as `settlement_period`: 2026-M01, 2026-Q1, 2026-S1.
     */
    public function label(): string
    {
        return match ($this->type) {
            self::MONTHLY => sprintf('%d-M%02d', $this->year, $this->number),
            self::QUARTERLY => sprintf('%d-Q%d', $this->year, $this->number),
            default => sprintf('%d-S%d', $this->year, $this->number),
        };
    }
}
