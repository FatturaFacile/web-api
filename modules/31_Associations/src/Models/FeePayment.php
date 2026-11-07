<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\PaymentMethod;

/**
 * A payment of an instalment. The accounting is on the linked invoice and payment of the company.
 */
final class FeePayment extends AssociationRecord
{
    protected $table = 'assoc_fee_payments';

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'paid_on' => 'date', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(FeeInstalment::class, 'fee_instalment_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }
}
