<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Services;

use Modules\SkiOutdoorSchool\Domain\Enums\RetentionMode;

/**
 * Gross fee of an instructor for a lesson or a course and the secretariat retention (trattenuta di segreteria) of the school.
 * All maths in whole cents: the gross fee is rate x minutes / 60, the retention is a percentage of the gross fee or a fixed fee per
 * worked hour (never more than the gross fee).
 *
 * @phpstan-type Breakdown array{gross_cents: int, retention_cents: int, net_cents: int}
 */
final class SecretariatCommissionCalculator
{
    /**
     * @return Breakdown
     */
    public function calculate(int $minutes, float $hourlyRate, RetentionMode $mode, float $retentionValue): array
    {
        $gross = (int) round($minutes * $this->cents($hourlyRate) / 60);

        $retention = match ($mode) {
            RetentionMode::PERCENT => (int) round($gross * $retentionValue / 100),
            RetentionMode::FIXED_HOURLY => (int) round($minutes * $this->cents($retentionValue) / 60),
            RetentionMode::NONE => 0,
        };
        $retention = max(0, min($gross, $retention));

        return ['gross_cents' => $gross, 'retention_cents' => $retention, 'net_cents' => $gross - $retention];
    }

    public function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function amount(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
