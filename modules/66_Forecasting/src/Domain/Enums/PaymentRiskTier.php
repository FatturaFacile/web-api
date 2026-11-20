<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Enums;

/**
 * Collection risk of a customer, derived from its recency-weighted payment delay.
 */
enum PaymentRiskTier: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Classifies a customer from the expected delay (days after the due date) and the probability of paying on time.
     */
    public static function classify(float $expectedDaysLate, float $onTimeProbability): self
    {
        return match (true) {
            $expectedDaysLate > 40.0 => self::Critical,
            $expectedDaysLate > 20.0 => self::High,
            $expectedDaysLate > 5.0 => self::Moderate,
            $onTimeProbability < 0.15 && $expectedDaysLate > 2.0 => self::Moderate,
            default => self::Low,
        };
    }

    /**
     * Ordering weight, 0 (Low) to 3 (Critical), for sorting and thresholds.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Moderate => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }
}
