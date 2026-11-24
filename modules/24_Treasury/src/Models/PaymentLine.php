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

    /**
     * The bank details of an employee are personal data: the ledger never forgets, so they stay out of it. Other recipients
     * are audited in full, as before.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        if ($this->recipient_type !== 'employee') {
            return $attributes;
        }

        return array_diff_key($attributes, array_flip(['recipient_iban', 'recipient_bic']));
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }
}
