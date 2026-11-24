<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * A statutory cash outflow: a tax, a social security installment or a payroll spike.
 */
final readonly class StatutoryCashEvent
{
    public const string CategoryTax = 'tax';

    public const string CategorySocial = 'social_security';

    public const string CategoryPayroll = 'payroll';

    public function __construct(
        /** Date the law sets, before the roll to the next business day. */
        public DateTimeImmutable $nominalDate,
        /** Date the cash leaves the account: the nominal date, or the next business day. */
        public DateTimeImmutable $date,
        public string $code,
        public string $category,
        public float $amount,
    ) {}

    /**
     * @return array{date: string, nominal_date: string, code: string, category: string, amount: float}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'nominal_date' => $this->nominalDate->format('Y-m-d'),
            'code' => $this->code,
            'category' => $this->category,
            'amount' => round($this->amount, 2),
        ];
    }
}
