<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Enums\SuspensionReason;
use Modules\Manufacturing\Enums\TimeCategory;

/**
 * A timestamped interval of a phase (Italian: intervallo di tempo): setup, run or suspension, with the operator and
 * whether the machine was attended. Durations are always derived from the server timestamps, never from client counters.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phase_id
 * @property string|null $operator_id
 * @property TimeCategory $category
 * @property bool $machine_attended
 * @property SuspensionReason|null $suspension_reason
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
final class MfgMesTimeLog extends BaseEnterpriseModel
{
    protected $table = 'mfg_mes_time_logs';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'category' => TimeCategory::class,
            'suspension_reason' => SuspensionReason::class,
            'machine_attended' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
