<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesBoundedContext\Domain\Enums\WelfareFiscalRegime;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $partner_dossier_id
 * @property string $convention_code
 * @property string $partner_company_name
 * @property string $corporate_email_domain
 * @property string $employee_email
 * @property string $cral_badge_number
 * @property bool $domain_and_cral_verified
 * @property WelfareFiscalRegime|null $welfare_regime
 * @property string $voucher_provider
 * @property string $gross_basket_amount
 * @property string $convention_discount_pct
 * @property string $discounted_net_amount
 * @property string $employer_welfare_quota_amount
 * @property string $employee_copay_quota_amount
 * @property string $regime_annual_limit
 * @property bool $within_statutory_exemption
 * @property string $currency
 * @property string $status
 */
final class SbcCorporateWelfareConvention extends BaseEnterpriseModel
{
    protected $table = 'sbc_corporate_welfare_conventions';

    protected function casts(): array
    {
        return [
            'domain_and_cral_verified' => 'boolean',
            'welfare_regime' => WelfareFiscalRegime::class,
            'gross_basket_amount' => 'decimal:2',
            'convention_discount_pct' => 'decimal:2',
            'discounted_net_amount' => 'decimal:2',
            'employer_welfare_quota_amount' => 'decimal:2',
            'employee_copay_quota_amount' => 'decimal:2',
            'regime_annual_limit' => 'decimal:2',
            'within_statutory_exemption' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function partnerDossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'partner_dossier_id');
    }
}
