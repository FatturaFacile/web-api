<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerLedgerEntry extends BaseEnterpriseModel
{
    protected $table = 'customer_ledger_entries';

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'due_date' => 'date',
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'cleared_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'cleared_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function getOutstandingAmountAttribute(): float
    {
        $net = (float) $this->debit_amount - (float) $this->credit_amount;

        return max(0.0, $net - (float) $this->cleared_amount);
    }
}
