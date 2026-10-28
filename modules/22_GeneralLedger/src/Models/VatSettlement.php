<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VatSettlement extends BaseEnterpriseModel
{
    protected $table = 'vat_settlements';

    protected function casts(): array
    {
        return [
            'output_vat_amount' => 'decimal:4',
            'input_vat_amount' => 'decimal:4',
            'prior_credit_used' => 'decimal:4',
            'net_vat_payable' => 'decimal:4',
            'net_vat_credit' => 'decimal:4',
        ];
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function settlementJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'settlement_journal_id');
    }
}
