<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One shift of a work centre on one day, in UTC, materialised from the weekly calendar and its dated exceptions. The
 * MES Core reads these intervals instead of interpreting weekdays and time zones itself.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scope_type
 * @property string $scope_id
 * @property string $shift_code
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property list<array<string, mixed>>|null $non_production
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgShiftInstance extends BaseEnterpriseModel
{
    /** The scope of the instances materialised from the work-centre calendars. */
    public const string ScopeWorkCenter = 'WorkCenter';

    protected $table = 'mfg_shift_instances';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'non_production' => 'array',
        ];
    }
}
