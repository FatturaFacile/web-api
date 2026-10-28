<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class JournalEntry extends BaseEnterpriseModel
{
    protected $table = 'journal_entries';

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'document_date' => 'date',
            'reversal_date' => 'date',
            'total_amount' => 'decimal:4',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(JournalBatch::class, 'batch_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_number');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_journal_entry_id');
    }

    public function customerLedgerItems(): HasMany
    {
        return $this->hasMany(CustomerLedger::class, 'journal_entry_id');
    }

    public function supplierLedgerItems(): HasMany
    {
        return $this->hasMany(SupplierLedger::class, 'journal_entry_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(AccountingAnomaly::class, 'journal_entry_id');
    }

    public function isBalanced(): bool
    {
        $debit = (float) $this->lines()->sum('debit_amount');
        $credit = (float) $this->lines()->sum('credit_amount');

        return abs($debit - $credit) < 0.0001 && $debit > 0;
    }
}
