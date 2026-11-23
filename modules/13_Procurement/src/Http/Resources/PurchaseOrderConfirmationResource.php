<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\PurchaseOrderConfirmation;

/**
 * A supplier order confirmation with the variances against the order.
 *
 * @mixin PurchaseOrderConfirmation
 */
final class PurchaseOrderConfirmationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'confirmation_number' => $this->confirmation_number,
            'has_variance' => $this->has_variance,
            'requires_review' => $this->requires_review,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmed_at->toDateString(),
            'price_variance_total' => (string) $this->price_variance_total,
            'quantity_variance_total' => (string) $this->quantity_variance_total,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn ($line): array => [
                'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                'ordered_quantity' => (string) $line->ordered_quantity,
                'confirmed_quantity' => (string) $line->confirmed_quantity,
                'ordered_unit_price' => (string) $line->ordered_unit_price,
                'confirmed_unit_price' => (string) $line->confirmed_unit_price,
                'requested_delivery_date' => $line->requested_delivery_date?->toDateString(),
                'confirmed_delivery_date' => $line->confirmed_delivery_date?->toDateString(),
                'quantity_variance' => (string) $line->quantity_variance,
                'quantity_variance_base' => (string) $line->quantity_variance_base,
                'price_variance' => (string) $line->price_variance,
                'price_variance_percent' => (string) $line->price_variance_percent,
                'line_total_variance' => (string) $line->line_total_variance,
                'delivery_delay_days' => (int) $line->delivery_delay_days,
                'variance_reason' => $line->variance_reason,
            ])->all()),
        ];
    }
}
