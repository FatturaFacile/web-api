<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The market development funds allocated to a partner for a fiscal year.
 */
final class MdfFund extends BaseEnterpriseModel
{
    protected $table = 'crm_mdf_funds';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'allocated_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * @return HasMany<MdfClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(MdfClaim::class, 'fund_id');
    }
}
