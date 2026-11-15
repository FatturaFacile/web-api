<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A channel partner (reseller, referral partner, distributor) with its program tier and the revenue it closed.
 */
final class Partner extends BaseEnterpriseModel
{
    public const array Types = ['reseller', 'referral', 'distributor', 'integrator'];

    public const array Statuses = ['prospect', 'active', 'suspended', 'terminated'];

    protected $table = 'crm_partners';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'closed_revenue' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PartnerTier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(PartnerTier::class, 'tier_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return HasMany<DealRegistration, $this>
     */
    public function dealRegistrations(): HasMany
    {
        return $this->hasMany(DealRegistration::class, 'partner_id');
    }

    /**
     * @return HasMany<MdfFund, $this>
     */
    public function mdfFunds(): HasMany
    {
        return $this->hasMany(MdfFund::class, 'partner_id');
    }
}
