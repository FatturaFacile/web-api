<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerCreditProfile extends BaseEnterpriseModel
{
    protected $table = 'customer_credit_profiles';

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'used_credit' => 'decimal:2',
            'pending_orders_amount' => 'decimal:2',
            'total_exposure' => 'decimal:2',
            'overdue_amount' => 'decimal:2',
            'overdue_invoices_count' => 'integer',
            'max_overdue_days' => 'integer',
            'is_credit_hold' => 'boolean',
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function hasAvailableCredit(string|float|int $amount): bool
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) {
            return true; // No limit configured or unlimited credit
        }

        $exposure = (float) $this->total_exposure;

        return ($exposure + (float) $amount) <= $limit;
    }
}
