<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\GeneralLedger\Services\JournalNumberSequence;
use Modules\GeneralLedger\Services\Tax\Decimal;

final class JournalEntry extends BaseEnterpriseModel
{
    protected $table = 'journal_entries';

    /**
     * An entry takes its progressive libro giornale number when it is posted, whichever path posts it.
     */
    protected static function booted(): void
    {
        parent::booted();

        self::saving(static function (self $entry): void {
            $becomesPosted = in_array($entry->getAttribute('status'), ['posted', 'reversed'], true)
                && (! $entry->exists || $entry->isDirty('status'));

            if ($becomesPosted) {
                app(JournalNumberSequence::class)->assign($entry);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'document_date' => 'date',
            'reversal_date' => 'date',
            'total_amount' => 'decimal:4',
            'journal_number' => 'integer',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'auto_reverse_on' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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
        $debit = '0';
        $credit = '0';

        foreach ($this->lines()->get(['debit_amount', 'credit_amount']) as $line) {
            $debit = Decimal::add($debit, (string) $line->debit_amount);
            $credit = Decimal::add($credit, (string) $line->credit_amount);
        }

        return Decimal::compare($debit, $credit) === 0 && Decimal::isPositive($debit);
    }
}
