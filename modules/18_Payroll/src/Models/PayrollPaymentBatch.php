<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Treasury\Models\PaymentBatch;

/**
 * A Treasury batch that pays salaries of a run.
 */
final class PayrollPaymentBatch extends PayrollRecord
{
    protected $table = 'payroll_payment_batches';

    /**
     * @return BelongsTo<PaymentBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }
}
