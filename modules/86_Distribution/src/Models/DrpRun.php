<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Distribution\Domain\Enums\BucketType;
use Modules\Distribution\Domain\Enums\RunStatus;

/**
 * One versioned DRP calculation: its parameters, its plan lines (time-phased grid rows) and its alerts.
 *
 * @property string $run_number
 * @property BucketType $bucket_type
 * @property int $horizon
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $parameters
 * @property array<string, mixed>|null $summary
 */
final class DrpRun extends BaseEnterpriseModel
{
    protected $table = 'distribution_drp_runs';

    protected function casts(): array
    {
        return [
            'bucket_type' => BucketType::class,
            'horizon' => 'integer',
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'parameters' => 'array',
            'summary' => 'array',
        ];
    }

    /**
     * @return HasMany<DrpPlanLine, $this>
     */
    public function planLines(): HasMany
    {
        return $this->hasMany(DrpPlanLine::class, 'drp_run_id');
    }

    /**
     * @return HasMany<DrpAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(DrpAlert::class, 'drp_run_id');
    }
}
