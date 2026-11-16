<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One calculation run: the volume invoiced from the start of the agreement up to the date, the rebate earned on it so
 * far and the part of it that was not accrued yet (the amount of this row).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property Carbon $as_of
 * @property string $base_amount
 * @property string $cumulative_rebate
 * @property string $rebate_amount
 * @property string|null $applied_rate_percent
 * @property string $status
 * @property string|null $settlement_id
 */
final class RebateAccrual extends BaseEnterpriseModel
{
    public const string Accrued = 'accrued';

    public const string Settled = 'settled';

    protected $table = 'rebate_accruals';

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'base_amount' => 'decimal:2',
            'cumulative_rebate' => 'decimal:2',
            'rebate_amount' => 'decimal:2',
            'applied_rate_percent' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<RebateAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'agreement_id');
    }
}
