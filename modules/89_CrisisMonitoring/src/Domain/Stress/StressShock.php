<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Stress;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The what-if shock of the stress test: revenue drop, collection delay (DSO shift), interest rate hike on the variable debt and an
 * extraordinary outflow. Every value is clamped to its range; the neutral shock changes nothing and is never saved.
 */
final readonly class StressShock
{
    public function __construct(
        public string $revenueDropPercent = '0',
        public int $dsoDelayDays = 0,
        public int $interestRateBps = 0,
        public string $oneOffOutflowAmount = '0',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            revenueDropPercent: self::clamp($data['revenue_drop_percent'] ?? 0, '0', '100'),
            dsoDelayDays: max(0, min(180, (int) ($data['dso_delay_days'] ?? 0))),
            interestRateBps: max(0, min(2000, (int) ($data['interest_rate_bps'] ?? 0))),
            oneOffOutflowAmount: self::clamp($data['one_off_outflow_amount'] ?? 0, '0', '999999999999'),
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'revenue_drop_percent' => $this->revenueDropPercent,
            'dso_delay_days' => $this->dsoDelayDays,
            'interest_rate_bps' => $this->interestRateBps,
            'one_off_outflow_amount' => $this->oneOffOutflowAmount,
        ];
    }

    public function isNeutral(): bool
    {
        return Decimal::isZero($this->revenueDropPercent) && $this->dsoDelayDays === 0 && $this->interestRateBps === 0 && Decimal::isZero($this->oneOffOutflowAmount);
    }

    private static function clamp(mixed $value, string $min, string $max): string
    {
        $number = is_scalar($value) && preg_match('/^[+-]?\d+(\.\d+)?$/', (string) $value) === 1 ? (string) $value : '0';

        if (Decimal::compare($number, $min) < 0) {
            return Decimal::round2($min);
        }

        return Decimal::round2(Decimal::compare($number, $max) > 0 ? $max : $number);
    }
}
