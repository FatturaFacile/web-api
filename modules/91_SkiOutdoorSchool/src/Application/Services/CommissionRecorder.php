<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Services;

use Modules\SkiOutdoorSchool\Domain\Enums\CommissionSource;
use Modules\SkiOutdoorSchool\Domain\Services\SecretariatCommissionCalculator;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;

/**
 * Records the fee of an instructor for a taught lesson or course with the secretariat retention of the instructor applied. Idempotent per
 * source: a line already attached to a payout statement is never changed.
 */
final class CommissionRecorder
{
    public function __construct(private readonly SecretariatCommissionCalculator $calculator) {}

    public function record(Instructor $instructor, CommissionSource $source, string $sourceId, string $workDate, int $minutes): InstructorCommission
    {
        $existing = InstructorCommission::query()
            ->where('instructor_id', $instructor->id)->where('source_type', $source->value)->where('source_id', $sourceId)
            ->first();

        if ($existing !== null && $existing->payout_statement_id !== null) {
            return $existing;
        }

        $result = $this->calculator->calculate($minutes, (float) $instructor->hourly_rate, $instructor->retention_mode, (float) $instructor->retention_value);
        $attributes = [
            'tenant_id' => (string) $instructor->tenant_id,
            'work_date' => $workDate,
            'hours' => round($minutes / 60, 2),
            'gross_fee' => $this->calculator->amount($result['gross_cents']),
            'retention_mode' => $instructor->retention_mode->value,
            'retention_value' => (float) $instructor->retention_value,
            'retention_amount' => $this->calculator->amount($result['retention_cents']),
            'net_fee' => $this->calculator->amount($result['net_cents']),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return InstructorCommission::query()->create($attributes + [
            'instructor_id' => (string) $instructor->id,
            'source_type' => $source->value,
            'source_id' => $sourceId,
        ]);
    }
}
