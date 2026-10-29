<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentApproval extends BaseEnterpriseModel
{
    protected $table = 'payment_approvals';

    protected function casts(): array
    {
        return [
            'approval_tier' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }
}
