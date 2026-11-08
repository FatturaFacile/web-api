<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use App\Services\Sales\DocumentTotalsCalculator;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Services\Tax\Decimal;

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

    /**
     * Whether the customer can take on `$amount` more without going over its limit; a limit of 0 means no limit.
     */
    public function hasAvailableCredit(string|float|int $amount): bool
    {
        $limit = DocumentTotalsCalculator::decimal($this->credit_limit);

        if (! Decimal::isPositive($limit)) {
            return true;
        }

        return Decimal::compare(Decimal::add(DocumentTotalsCalculator::decimal($this->total_exposure), DocumentTotalsCalculator::decimal($amount)), $limit) <= 0;
    }
}
