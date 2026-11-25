<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string $affiliate_code
 * @property string $franchisor_brand
 * @property string $store_name
 * @property string $country
 * @property string $currency
 * @property string $sell_out_turnover
 * @property string $royalty_rate_pct
 * @property string $marketing_fund_rate_pct
 * @property string $calculated_royalty_amount
 * @property string $calculated_marketing_fund_amount
 * @property string $total_fee_invoiceable
 * @property string $pushed_price_list_version
 * @property string $network_avg_turnover
 * @property string $benchmark_index_pct
 * @property string $status
 */
final class SbcFranchisingAffiliate extends BaseEnterpriseModel
{
    protected $table = 'sbc_franchising_affiliates';

    protected function casts(): array
    {
        return [
            'sell_out_turnover' => 'decimal:2',
            'royalty_rate_pct' => 'decimal:2',
            'marketing_fund_rate_pct' => 'decimal:2',
            'calculated_royalty_amount' => 'decimal:2',
            'calculated_marketing_fund_amount' => 'decimal:2',
            'total_fee_invoiceable' => 'decimal:2',
            'network_avg_turnover' => 'decimal:2',
            'benchmark_index_pct' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'dossier_id');
    }
}
