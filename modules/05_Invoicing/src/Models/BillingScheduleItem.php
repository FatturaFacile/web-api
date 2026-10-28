<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BillingScheduleItem extends BaseEnterpriseModel
{
    protected $table = 'billing_schedule_items';

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'invoiced_at' => 'datetime',
            'percentage' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BillingSchedule::class, 'billing_schedule_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
