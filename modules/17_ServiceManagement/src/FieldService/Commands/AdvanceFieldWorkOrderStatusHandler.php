<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Moves a dispatched work order along the technician's journey (dispatched -> en_route -> on_site). Asking for the
 * status the work order already has is a no-op, so a replayed offline event is harmless.
 */
final class AdvanceFieldWorkOrderStatusHandler
{
    /** @var array<string, list<string>> */
    private const Allowed = [
        'en_route' => ['dispatched'],
        'on_site' => ['dispatched', 'en_route'],
    ];

    public function __invoke(string $workOrderId, string $target, ?CarbonImmutable $at = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrderId, $target, $at): WorkOrder {
            $workOrder = WorkOrder::query()->whereKey($workOrderId)->lockForUpdate()->firstOrFail();

            if ($workOrder->status === $target) {
                return $workOrder;
            }

            if (! isset(self::Allowed[$target]) || ! in_array($workOrder->status, self::Allowed[$target], true)) {
                throw ValidationException::withMessages(['status' => __('field_service.errors.transition_invalid', ['from' => (string) $workOrder->status, 'to' => $target])]);
            }

            $attributes = ['status' => $target];

            if ($target === 'on_site') {
                $attributes['actual_arrival'] = $at ?? now();
                $attributes['actual_start'] ??= $workOrder->actual_start ?? ($at ?? now());
            }

            $workOrder->update($attributes);

            return $workOrder;
        });
    }
}
