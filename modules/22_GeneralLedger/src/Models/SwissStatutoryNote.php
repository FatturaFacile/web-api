<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Swiss statutory notes to the financial statements (Anhang, CO Art. 959c) for a fiscal year.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $fiscal_year_id
 * @property string $fte_band
 * @property string|null $valuation_principles
 * @property string|null $pledged_assets_amount
 * @property string|null $pledged_assets_details
 * @property string|null $leasing_commitments_amount
 * @property string|null $leasing_commitments_details
 * @property string|null $pension_fund_liabilities_amount
 * @property string|null $pension_fund_liabilities_details
 * @property array<int|string, mixed>|null $fire_insurance_values
 * @property string|null $subsequent_events
 * @property string|null $auditor_fees_audit
 * @property string|null $auditor_fees_other
 * @property array<string, mixed>|null $extra_disclosures
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 */
final class SwissStatutoryNote extends BaseEnterpriseModel
{
    protected $table = 'gl_swiss_statutory_notes';

    protected function casts(): array
    {
        return [
            'pledged_assets_amount' => 'decimal:4',
            'leasing_commitments_amount' => 'decimal:4',
            'pension_fund_liabilities_amount' => 'decimal:4',
            'fire_insurance_values' => 'array',
            'auditor_fees_audit' => 'decimal:4',
            'auditor_fees_other' => 'decimal:4',
            'extra_disclosures' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
