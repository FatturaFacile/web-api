<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A month of the schedule: interest, payment, principal and closing balance of the lease liability, and the
 * depreciation and carrying amount of the right-of-use asset.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lease_contract_id
 * @property string $schedule_version_id
 * @property int $period_number
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $opening_liability
 * @property string $payment
 * @property string $interest
 * @property string $principal
 * @property string $closing_liability
 * @property string $rou_opening
 * @property string $depreciation
 * @property string $rou_closing
 * @property string $status
 */
final class LeaseScheduleLine extends BaseEnterpriseModel
{
    /** A superseded row was replaced by a remeasurement and is kept for the history of the version that produced it. */
    public const array Statuses = ['scheduled', 'posted', 'superseded'];

    protected $table = 'lease_schedule_lines';

    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'opening_liability' => 'decimal:2',
            'payment' => 'decimal:2',
            'interest' => 'decimal:2',
            'principal' => 'decimal:2',
            'closing_liability' => 'decimal:2',
            'rou_opening' => 'decimal:2',
            'depreciation' => 'decimal:2',
            'rou_closing' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<LeaseScheduleVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LeaseScheduleVersion::class, 'schedule_version_id');
    }
}
