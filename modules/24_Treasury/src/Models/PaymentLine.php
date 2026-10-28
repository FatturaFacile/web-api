<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentLine extends BaseEnterpriseModel
{
    protected $table = 'payment_lines';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'execution_date' => 'date',
            'line_number' => 'integer',
        ];
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }
}
