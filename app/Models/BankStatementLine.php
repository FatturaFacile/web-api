<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bank statement. `amount` is the net amount booked, `fee_amount` the fee withheld by the bank, so the
 * gross amount paid by the debtor is `amount + fee_amount`.
 */
class BankStatementLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    public const EXACT = 'exact';

    public const QR_REFERENCE = 'qr_reference';

    public const FUZZY_TOLERANCE = 'fuzzy_tolerance';

    public const PARTIAL = 'partial';

    public const MANUAL = 'manual';

    public const AUTO_CATEGORIZATION = 'auto_categorization';

    public const MANUAL_CATEGORIZATION = 'manual_categorization';

    public const CATEGORY_INVOICE = 'invoice';

    public const CATEGORY_TAX_PAYMENT = 'tax_payment';

    public const CATEGORY_PAYROLL = 'payroll';

    public const CATEGORY_BANK_EXPENSE = 'bank_expense';

    public const CATEGORY_INTERNAL_TRANSFER = 'internal_transfer';

    public const CATEGORY_GENERIC_EXPENSE = 'generic_expense';

    /**
     * Categories for outflows that have no invoice.
     *
     * @var list<string>
     */
    public const NON_INVOICE_CATEGORIES = ['tax_payment', 'payroll', 'bank_expense', 'internal_transfer', 'generic_expense'];

    protected $fillable = [
        'tenant_id',
        'bank_statement_id',
        'line_number',
        'booking_date',
        'value_date',
        'amount',
        'fee_amount',
        'direction',
        'abi_code',
        'reference',
        'qr_reference',
        'description',
        'counterpart_name',
        'counterpart_iban',
        'reconciled',
        'matched_document_id',
        'reconciliation_type',
        'reconciliation_category',
        'ledger_account_code',
        'reconciliation_notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'reconciled' => 'boolean',
        ];
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }

    /**
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function matchedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'matched_document_id');
    }
}
