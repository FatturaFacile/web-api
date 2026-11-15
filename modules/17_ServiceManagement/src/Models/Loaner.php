<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ServiceManagement\Enums\LoanerRegisterType;
use Modules\ServiceManagement\Enums\LoanerStatus;
use Modules\ServiceManagement\Enums\RmaStatus;

/**
 * Loaner / repair-account register entry ("prestito d'uso" or "conto riparazione").
 *
 * @property string $id
 * @property string $rma_order_id
 * @property string|null $loaner_stock_serial_number_id
 * @property string $loaner_serial_number
 * @property string|null $loaner_product_id
 * @property string $customer_id
 * @property string $register_type
 * @property \Illuminate\Support\Carbon $issued_at
 * @property \Illuminate\Support\Carbon|null $expected_return_at
 * @property \Illuminate\Support\Carbon|null $returned_at
 * @property string $status
 * @property string|null $ddt_causale
 * @property \Illuminate\Support\Carbon|null $overdue_alerted_at
 * @property string|null $notes
 */
final class Loaner extends BaseEnterpriseModel
{
    protected $table = 'esm_loaners';

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'returned_at' => 'datetime',
            'overdue_alerted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DepotRepairRmaOrder, $this>
     */
    public function rmaOrder(): BelongsTo
    {
        return $this->belongsTo(DepotRepairRmaOrder::class, 'rma_order_id');
    }

    /**
     * A "prestito d'uso" unit is overdue when it is still out after the repaired equipment went back to the
     * customer (RMA returned/closed, or cancelled while the loaner was out) for more than the grace period.
     */
    public function isOverdueAt(CarbonInterface $now, int $graceDays): bool
    {
        if ($this->register_type !== LoanerRegisterType::PrestitoUso->value || $this->status !== LoanerStatus::Issued->value) {
            return false;
        }

        $rma = $this->rmaOrder;
        if ($rma === null || ! in_array($rma->status, [RmaStatus::Returned->value, RmaStatus::Closed->value, RmaStatus::Cancelled->value], true)) {
            return false;
        }

        $returnedAt = $rma->returned_at ?? $rma->updated_at;

        return $returnedAt !== null && $returnedAt->copy()->addDays($graceDays)->lessThan($now);
    }

    public function daysOutstandingAt(CarbonInterface $now): int
    {
        $end = $this->returned_at ?? $now;

        return max(0, (int) floor($this->issued_at->diffInDays($end, true)));
    }
}
