<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An open item inside a payment run, with the Skonto, the credit notes offset and the cash to transfer for it.
 *
 * @property string $id
 * @property string $payment_run_id
 * @property string $open_item_id
 * @property string|null $transfer_id
 * @property string $supplier_id
 * @property string $kind
 * @property string $selection_reason
 * @property string $open_amount
 * @property string|null $skonto_percentage
 * @property Carbon|null $skonto_deadline
 * @property string $skonto_amount
 * @property string $credit_applied_amount
 * @property string $cash_amount
 * @property string $credit_used_amount
 */
final class ApPaymentRunItem extends BaseEnterpriseModel
{
    public const string ReasonDue = 'due';

    public const string ReasonSkonto = 'skonto';

    public const string ReasonCreditNote = 'credit_note';

    protected $table = 'ap_payment_run_items';

    protected function casts(): array
    {
        return [
            'open_amount' => 'decimal:2',
            'skonto_percentage' => 'decimal:2',
            'skonto_deadline' => 'date',
            'skonto_amount' => 'decimal:2',
            'credit_applied_amount' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'credit_used_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ApOpenItem, $this>
     */
    public function openItem(): BelongsTo
    {
        return $this->belongsTo(ApOpenItem::class, 'open_item_id');
    }

    /**
     * @return BelongsTo<ApPaymentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ApPaymentRun::class, 'payment_run_id');
    }
}
