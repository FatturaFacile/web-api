<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\Loaner;

/**
 * @mixin Loaner
 */
final class LoanerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = now();
        $graceDays = (int) config('service-management.rma.loaner_grace_days', 7);

        return [
            'id' => $this->id,
            'rma_order_id' => $this->rma_order_id,
            'loaner_serial_number' => $this->loaner_serial_number,
            'loaner_stock_serial_number_id' => $this->loaner_stock_serial_number_id,
            'loaner_product_id' => $this->loaner_product_id,
            'customer_id' => $this->customer_id,
            'register_type' => $this->register_type,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'expected_return_at' => $this->expected_return_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'status' => $this->status,
            'ddt_causale' => $this->ddt_causale,
            'is_overdue' => $this->resource->isOverdueAt($now, $graceDays),
            'days_outstanding' => $this->resource->daysOutstandingAt($now),
            'overdue_alerted_at' => $this->overdue_alerted_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
