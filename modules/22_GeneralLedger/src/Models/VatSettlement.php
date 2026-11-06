<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Exceptions\VatSettlementException;

final class VatSettlement extends BaseEnterpriseModel
{
    public const string STATUS_CALCULATED = 'calculated';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_PAID = 'paid';

    /** The only columns that may change on a posted settlement, to record its payment. */
    private const array PAYMENT_COLUMNS = ['status', 'is_paid', 'payment_date', 'paid_at', 'paid_by', 'payment_journal_id', 'updated_at'];

    protected $table = 'vat_settlements';

    protected static function booted(): void
    {
        parent::booted();

        self::updating(static function (self $settlement): void {
            $original = (string) $settlement->getOriginal('status');

            if ($original === self::STATUS_CALCULATED) {
                return;
            }

            if ($original === self::STATUS_PAID || array_diff(array_keys($settlement->getDirty()), self::PAYMENT_COLUMNS) !== []) {
                throw VatSettlementException::conflict('A posted VAT settlement is immutable.');
            }
        });

        self::deleting(static function (self $settlement): void {
            if ($settlement->isFinal()) {
                throw VatSettlementException::conflict('A posted VAT settlement is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'period' => 'integer',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'output_vat_amount' => 'decimal:4',
            'input_vat_amount' => 'decimal:4',
            'prior_credit_used' => 'decimal:4',
            'net_vat_payable' => 'decimal:4',
            'net_vat_credit' => 'decimal:4',
            'turnover' => 'decimal:2',
            'taxable_turnover' => 'decimal:2',
            'passive_operations' => 'decimal:2',
            'previous_debit' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'total_debit_vat' => 'decimal:2',
            'total_credit_vat' => 'decimal:2',
            'below_minimum_payment' => 'boolean',
            'is_paid' => 'boolean',
            'payment_date' => 'immutable_date',
            'rate_breakdown' => 'array',
            'warnings' => 'array',
            'posted_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_POSTED, self::STATUS_PAID], true);
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
