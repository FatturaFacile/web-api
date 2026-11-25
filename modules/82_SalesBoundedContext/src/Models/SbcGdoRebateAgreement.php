<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string|null $settlement_credit_note_id
 * @property string $agreement_code
 * @property string $gdo_chain_name
 * @property int $contract_year
 * @property string $currency
 * @property string $sell_in_volume_amount
 * @property string $sell_out_promo_contribution
 * @property string $resolved_tier_rate_pct
 * @property string $annual_pfa_rebate_amount
 * @property string $monthly_accrual_rateo
 * @property int $accrued_months_count
 * @property string $total_provisioned_accrual
 * @property string $settlement_status
 */
final class SbcGdoRebateAgreement extends BaseEnterpriseModel
{
    protected $table = 'sbc_gdo_rebate_agreements';

    protected function casts(): array
    {
        return [
            'contract_year' => 'integer',
            'tier_brackets' => 'array',
            'sell_in_volume_amount' => 'decimal:2',
            'sell_out_promo_contribution' => 'decimal:2',
            'resolved_tier_rate_pct' => 'decimal:2',
            'annual_pfa_rebate_amount' => 'decimal:2',
            'monthly_accrual_rateo' => 'decimal:2',
            'accrued_months_count' => 'integer',
            'total_provisioned_accrual' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'dossier_id');
    }

    /**
     * @return BelongsTo<SbcDdtInvoice, $this>
     */
    public function settlementCreditNote(): BelongsTo
    {
        return $this->belongsTo(SbcDdtInvoice::class, 'settlement_credit_note_id');
    }
}
