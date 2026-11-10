<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Swiss statutory notes to the financial statements (Anhang, CO Art. 959c) for a fiscal year.
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
