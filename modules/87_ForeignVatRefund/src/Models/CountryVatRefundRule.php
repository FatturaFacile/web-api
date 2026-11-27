<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Modules\ForeignVatRefund\Domain\Enums\RegimeType;

/**
 * One cell of the reciprocity and deductibility matrix: what share of the VAT of an expense category a company established in
 * `home_country_code` can claim back from `refund_country_code`, with the thresholds and the peremptory deadline of the procedure.
 * Global rows (tenant_id null) are the seeded defaults; a company overrides a cell with a row of its own.
 *
 * @property string $home_country_code
 * @property string $refund_country_code
 * @property RegimeType $regime_type
 * @property bool $has_reciprocity
 * @property int $expense_category_code
 * @property string|null $sub_code
 * @property string $deductible_percentage
 * @property string|null $min_annual_amount
 * @property string|null $min_quarterly_amount
 * @property string $currency_code
 * @property int $filing_deadline_month
 * @property int $filing_deadline_day
 * @property list<string>|null $vat_rates
 * @property bool $requires_fiscal_representative
 * @property bool $requires_taxable_status_certificate
 * @property string|null $notes_key
 * @property bool $is_active
 */
final class CountryVatRefundRule extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'country_vat_refund_rules';

    protected function casts(): array
    {
        return [
            'regime_type' => RegimeType::class,
            'has_reciprocity' => 'boolean',
            'expense_category_code' => 'integer',
            'deductible_percentage' => 'decimal:2',
            'min_annual_amount' => 'decimal:2',
            'min_quarterly_amount' => 'decimal:2',
            'filing_deadline_month' => 'integer',
            'filing_deadline_day' => 'integer',
            'vat_rates' => 'array',
            'requires_fiscal_representative' => 'boolean',
            'requires_taxable_status_certificate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
