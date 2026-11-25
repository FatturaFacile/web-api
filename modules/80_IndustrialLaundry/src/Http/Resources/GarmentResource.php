<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Support\ContactName;

/**
 * A tagged garment; the detail adds its history when the events are loaded.
 *
 * @mixin Garment
 */
final class GarmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'epc_uid_rfid_hex' => $this->epc_uid_rfid_hex,
            'thermal_barcode' => $this->thermal_barcode,
            'article_type' => $this->article_type->value,
            'product_id' => $this->product_id,
            'description' => $this->description,
            'assigned_customer_id' => $this->assigned_customer_id,
            'customer' => $this->relationLoaded('customer') ? ContactName::summary($this->customer) : null,
            'assigned_wearer_employee_name' => $this->assigned_wearer_employee_name,
            'assigned_locker_code' => $this->assigned_locker_code,
            'wash_cycles_count' => $this->wash_cycles_count,
            'max_certified_wash_cycles_limit' => $this->max_certified_wash_cycles_limit,
            'remaining_washes' => $this->remainingWashes(),
            'ppe_certification_standard' => $this->ppe_certification_standard,
            'ppe_category' => $this->ppe_category,
            'current_lifecycle_state' => $this->current_lifecycle_state->value,
            'is_reissue_blocked' => $this->is_reissue_blocked,
            'last_scanned_at' => $this->last_scanned_at?->toIso8601String(),
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
            'last_soiled_at' => $this->last_soiled_at?->toIso8601String(),
            'condemned_at' => $this->condemned_at?->toIso8601String(),
            'mending_or_condemn_reason' => $this->mending_or_condemn_reason,
            'events' => $this->relationLoaded('events') ? $this->events->map(static fn ($event): array => [
                'id' => (string) $event->id,
                'event_type' => $event->event_type->value,
                'from_state' => $event->from_state?->value,
                'to_state' => $event->to_state?->value,
                'wash_cycles_count' => $event->wash_cycles_count,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ])->values()->all() : null,
        ];
    }
}
