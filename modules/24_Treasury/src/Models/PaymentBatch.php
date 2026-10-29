<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Carbon\Carbon;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentBatch extends BaseEnterpriseModel
{
    protected $table = 'payment_batches';

    protected function casts(): array
    {
        return [
            'execution_date' => 'date',
            'total_amount' => 'decimal:2',
            'item_count' => 'integer',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentLine::class, 'payment_batch_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PaymentApproval::class, 'payment_batch_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(PaymentExecution::class, 'payment_batch_id');
    }

    public function getApprovedAtAttribute(): ?Carbon
    {
        $approval = $this->approvals()->latest()->first();

        return $approval?->approved_at ?? ($this->status === 'approved' ? $this->updated_at : null);
    }
}
