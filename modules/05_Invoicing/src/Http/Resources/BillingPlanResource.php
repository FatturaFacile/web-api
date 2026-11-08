<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\BillingSchedule;
use Modules\Invoicing\Models\BillingScheduleItem;

/**
 * A billing plan of a sales order with its items. Amounts are decimal strings.
 *
 * @mixin BillingSchedule
 */
final class BillingPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_number' => $this->schedule_number,
            'sales_order_id' => $this->sales_order_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'total_amount' => (string) $this->total_amount,
            'billed_amount' => (string) $this->billed_amount,
            'remaining_amount' => (string) $this->remaining_amount,
            'start_date' => ($this->getAttribute('start_date') instanceof \DateTimeInterface ? $this->getAttribute('start_date')->format('Y-m-d') : null),
            'end_date' => ($this->getAttribute('end_date') instanceof \DateTimeInterface ? $this->getAttribute('end_date')->format('Y-m-d') : null),
            'next_billing_date' => ($this->getAttribute('next_billing_date') instanceof \DateTimeInterface ? $this->getAttribute('next_billing_date')->format('Y-m-d') : null),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->sortBy('target_date')->values()->map(static fn (BillingScheduleItem $item): array => [
                'id' => $item->id,
                'name' => $item->milestone_name,
                'milestone_id' => $item->milestone_id,
                'percentage' => $item->percentage === null ? null : (string) $item->percentage,
                'amount' => (string) $item->amount,
                'target_date' => ($item->getAttribute('target_date') instanceof \DateTimeInterface ? $item->getAttribute('target_date')->format('Y-m-d') : null),
                'status' => $item->status,
                'invoice_id' => $item->invoice_id,
                'invoiced_at' => $item->getAttribute('invoiced_at') instanceof \DateTimeInterface ? $item->getAttribute('invoiced_at')->format(DATE_ATOM) : null,
            ])->all()),
            'created_at' => $this->getAttribute('created_at') instanceof \DateTimeInterface ? $this->getAttribute('created_at')->format(DATE_ATOM) : null,
        ];
    }
}
