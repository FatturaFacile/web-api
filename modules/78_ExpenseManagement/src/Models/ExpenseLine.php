<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;

/**
 * @property string $expense_report_id
 * @property Carbon $expense_date
 * @property string $category_id
 * @property string $merchant_name
 * @property string|null $merchant_tax_id
 * @property string|null $description
 * @property string $original_amount
 * @property string $original_currency
 * @property string $exchange_rate
 * @property Carbon|null $exchange_rate_date
 * @property string $amount_in_base_currency
 * @property string $vat_rate
 * @property string $vat_amount
 * @property string $vat_recoverable_amount
 * @property PaymentMethod $payment_method
 * @property string|null $corporate_card_transaction_id
 * @property bool $is_traceable_payment
 * @property bool $is_taxable_benefit
 * @property bool $is_reimbursable
 * @property list<array<string, mixed>>|null $policy_violations
 * @property string|null $project_id
 * @property bool $billable_to_customer
 * @property string|null $receipt_id
 * @property string|null $receipt_document_id
 * @property string|null $receipt_sha256
 * @property string|null $normalized_merchant
 */
final class ExpenseLine extends BaseEnterpriseModel
{
    protected $table = 'exm_lines';

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'exchange_rate_date' => 'date',
            'original_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'amount_in_base_currency' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:2',
            'vat_recoverable_amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'is_traceable_payment' => 'boolean',
            'is_taxable_benefit' => 'boolean',
            'is_reimbursable' => 'boolean',
            'policy_violations' => 'array',
            'billable_to_customer' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ExpenseReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /**
     * @return HasOne<MileageClaim, $this>
     */
    public function mileageClaim(): HasOne
    {
        return $this->hasOne(MileageClaim::class, 'expense_line_id');
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * @return BelongsTo<CorporateCardTransaction, $this>
     */
    public function cardTransaction(): BelongsTo
    {
        return $this->belongsTo(CorporateCardTransaction::class, 'corporate_card_transaction_id');
    }

    public function hasBlockingViolation(): bool
    {
        foreach ($this->policy_violations ?? [] as $violation) {
            if (($violation['severity'] ?? null) === 'blocking') {
                return true;
            }
        }

        return false;
    }
}
