<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One measurement of a lease: the initial one (version 1) and every remeasurement after a modification, an index change
 * or the reassessment of an option. A remeasurement supersedes the version in force from its effective date; the rows
 * before that date stay with the version that produced them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lease_contract_id
 * @property int $version_number
 * @property string $reason
 * @property Carbon $effective_date
 * @property int $start_period_number
 * @property int $end_period_number
 * @property string $discount_rate_percent
 * @property string $payment_amount
 * @property string $liability_before
 * @property string $liability_after
 * @property string $rou_before
 * @property string $rou_after
 * @property string $liability_adjustment
 * @property string $rou_adjustment
 * @property string $result_amount
 * @property string $total_undiscounted
 * @property string|null $journal_entry_id
 * @property string $status
 * @property string|null $notes
 * @property string|null $created_by
 */
final class LeaseScheduleVersion extends BaseEnterpriseModel
{
    /** `initial` is version 1; the others are the reasons of a remeasurement. */
    public const array Reasons = ['initial', 'modification', 'index_change', 'reassessment'];

    protected $table = 'lease_schedule_versions';

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_date' => 'date',
            'start_period_number' => 'integer',
            'end_period_number' => 'integer',
            'discount_rate_percent' => 'decimal:6',
            'payment_amount' => 'decimal:2',
            'liability_before' => 'decimal:2',
            'liability_after' => 'decimal:2',
            'rou_before' => 'decimal:2',
            'rou_after' => 'decimal:2',
            'liability_adjustment' => 'decimal:2',
            'rou_adjustment' => 'decimal:2',
            'result_amount' => 'decimal:2',
            'total_undiscounted' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<LeaseContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class, 'lease_contract_id');
    }

    /**
     * @return HasMany<LeaseScheduleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(LeaseScheduleLine::class, 'schedule_version_id')->orderBy('period_number');
    }
}
