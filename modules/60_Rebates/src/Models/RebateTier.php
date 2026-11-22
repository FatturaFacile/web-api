<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A step of the rebate scale: from the threshold of cumulative volume the rate applies.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property string $threshold_amount
 * @property string $rate_percent
 * @property string|null $flat_amount
 */
final class RebateTier extends BaseEnterpriseModel
{
    protected $table = 'rebate_tiers';

    protected function casts(): array
    {
        return ['threshold_amount' => 'decimal:2', 'rate_percent' => 'decimal:4', 'flat_amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<RebateAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'agreement_id');
    }
}
