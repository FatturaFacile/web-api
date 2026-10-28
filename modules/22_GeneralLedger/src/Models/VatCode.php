<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VatCode extends BaseEnterpriseModel
{
    protected $table = 'vat_codes';

    protected function casts(): array
    {
        return [
            'tax_rate_percent' => 'decimal:2',
            'is_reverse_charge' => 'boolean',
            'is_split_payment' => 'boolean',
            'is_non_deductible' => 'boolean',
            'non_deductible_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function salesTaxAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_sales_tax_account_id');
    }

    public function purchaseTaxAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_purchase_tax_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'vat_code_id');
    }
}
