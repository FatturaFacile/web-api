<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Concerns;

use App\Models\Tenant;
use App\Support\Database\Concerns\ProtectsRecords;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\HumanResources\Services\TimeTracking\DataRetentionPolicy;

/**
 * Blocks physical deletion (forceDelete) of a time record within the jurisdiction-aware legal retention period.
 * Soft deletes (logical, append-only deletions that preserve the audit trail) remain allowed.
 *
 * @phpstan-require-extends Model
 */
trait ProtectsTimeRecord
{
    use ProtectsRecords;

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation !== 'delete') {
            return null;
        }

        // Allow soft deletes; only block physical (force) deletions.
        if (method_exists($this, 'isForceDeleting') && ! $this->isForceDeleting()) {
            return null;
        }

        $country = Tenant::query()->whereKey($this->tenant_id)->value('country') ?? 'CH';

        $minimumYears = $country === 'IT'
            ? DataRetentionPolicy::IT_MINIMUM_YEARS
            : DataRetentionPolicy::MINIMUM_YEARS;

        $recordDate = $this->getAttribute('occurred_at') ?? $this->getAttribute('work_date');
        if ($recordDate === null) {
            return null;
        }

        $date = $recordDate instanceof DateTimeInterface
            ? CarbonImmutable::instance($recordDate)
            : CarbonImmutable::parse((string) $recordDate);

        $cutoff = CarbonImmutable::now()->subYears($minimumYears)->startOfYear();

        if ($date->gt($cutoff)) {
            return sprintf(
                'Cannot permanently delete a time record within the %d-year legal retention period (jurisdiction: %s).',
                $minimumYears,
                $country,
            );
        }

        return null;
    }
}
