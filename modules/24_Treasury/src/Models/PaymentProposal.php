<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier payment proposal: the open supplier invoices due by a date, or whose early-payment discount is still
 * available, selected for a bank account. Someone other than its author approves it (segregation of duties) and the
 * approval turns it into an approved payment batch, exported as pain.001 by the payment factory.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $proposal_number
 * @property string $bank_account_id
 * @property string $status
 * @property string $format
 * @property Carbon $execution_date
 * @property Carbon $due_until
 * @property bool $include_discounts
 * @property string $currency
 * @property string $total_amount
 * @property string $total_discount
 * @property int $item_count
 * @property string $created_by
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property string|null $payment_batch_id
 */
final class PaymentProposal extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Converted = 'converted';

    public const string Rejected = 'rejected';

    protected $table = 'treasury_payment_proposals';

    protected function casts(): array
    {
        return [
            'execution_date' => 'date',
            'due_until' => 'date',
            'include_discounts' => 'boolean',
            'total_amount' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'item_count' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaymentProposalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentProposalLine::class, 'payment_proposal_id');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<PaymentBatch, $this>
     */
    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }
}
