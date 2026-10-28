<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BillingSchedule extends BaseEnterpriseModel
{
    protected $table = 'billing_schedules';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_billing_date' => 'date',
            'total_amount' => 'decimal:2',
            'billed_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(CustomerAgreement::class, 'customer_agreement_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingScheduleItem::class, 'billing_schedule_id');
    }
}
