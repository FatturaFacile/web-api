<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentExecution extends BaseEnterpriseModel
{
    protected $table = 'payment_executions';

    protected function casts(): array
    {
        return [
            'clearing_timestamp' => 'datetime',
            'response_payload_json' => 'array',
        ];
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function getExecutionStatusAttribute(): string
    {
        return 'TRANSMITTED';
    }
}
