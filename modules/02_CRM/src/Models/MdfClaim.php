<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's claim against an MDF fund. submitted -> approved | rejected; approved -> paid.
 */
final class MdfClaim extends BaseEnterpriseModel
{
    public const array Statuses = ['submitted', 'approved', 'rejected', 'paid'];

    protected $table = 'crm_mdf_claims';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'activity_date' => 'date',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MdfFund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(MdfFund::class, 'fund_id');
    }
}
