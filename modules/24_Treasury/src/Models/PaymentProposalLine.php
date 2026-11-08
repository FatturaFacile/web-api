<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One supplier invoice of a payment proposal: why it was selected (`due` or `discount`), its open amount, the
 * early-payment discount taken and the amount to pay.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_proposal_id
 * @property string $passive_invoice_id
 * @property string $supplier_id
 * @property string $selection_reason
 * @property Carbon|null $due_date
 * @property Carbon|null $discount_deadline
 * @property string $open_amount
 * @property string $discount_amount
 * @property string $amount
 * @property string|null $discount_payment_id
 */
final class PaymentProposalLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const string ReasonDue = 'due';

    public const string ReasonDiscount = 'discount';

    protected $table = 'treasury_payment_proposal_lines';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'discount_deadline' => 'date',
            'open_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'passive_invoice_id');
    }
}
