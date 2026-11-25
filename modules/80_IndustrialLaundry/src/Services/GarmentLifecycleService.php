<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\GarmentEvent;

/**
 * Moves a garment through its lifecycle and keeps its history: the state, the timestamps of the last delivery, soiling and
 * condemnation, the wash cycle counter and one event per step.
 */
final class GarmentLifecycleService
{
    /**
     * @param  array{scan_session_id?: string|null, contact_id?: string|null, note?: string|null, reason?: string|null, count_wash?: bool, userId?: string|null}  $context
     */
    public function move(Garment $garment, LifecycleState $to, GarmentEventType $event, Carbon $at, array $context = []): Garment
    {
        $from = $garment->current_lifecycle_state;

        if ($from !== $to && ! $from->canMoveTo($to)) {
            throw new IndustrialLaundryException('invalid_lifecycle_transition', 422, ['from' => $from->value, 'to' => $to->value]);
        }

        $attributes = ['current_lifecycle_state' => $to->value, 'last_scanned_at' => $at, 'updated_by' => $context['userId'] ?? null];

        if (($context['count_wash'] ?? false) === true) {
            $attributes['wash_cycles_count'] = $garment->wash_cycles_count + 1;
        }

        match ($to) {
            LifecycleState::DeliveredToCustomer => $attributes += ['last_delivered_at' => $at, 'unreturned_billed_at' => null],
            LifecycleState::SoiledReturned => $attributes += ['last_soiled_at' => $at],
            LifecycleState::CondemnedRag => $attributes += ['condemned_at' => $at, 'mending_or_condemn_reason' => $context['reason'] ?? null],
            LifecycleState::InMending => $attributes += ['mending_or_condemn_reason' => $context['reason'] ?? null],
            default => null,
        };

        $garment->forceFill($attributes)->save();
        $this->record($garment, $event, $at, $from, $to, $context);

        return $garment;
    }

    /**
     * @param  array{scan_session_id?: string|null, contact_id?: string|null, note?: string|null, userId?: string|null}  $context
     */
    public function record(Garment $garment, GarmentEventType $event, Carbon $at, ?LifecycleState $from = null, ?LifecycleState $to = null, array $context = []): GarmentEvent
    {
        return GarmentEvent::query()->create([
            'tenant_id' => $garment->tenant_id,
            'garment_id' => $garment->id,
            'scan_session_id' => $context['scan_session_id'] ?? null,
            'contact_id' => $context['contact_id'] ?? $garment->assigned_customer_id,
            'article_type' => $garment->article_type->value,
            'event_type' => $event->value,
            'from_state' => $from?->value,
            'to_state' => $to?->value,
            'wash_cycles_count' => $garment->wash_cycles_count,
            'note' => $context['note'] ?? null,
            'occurred_at' => $at,
            'created_by' => $context['userId'] ?? null,
        ]);
    }
}
