<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Wms\Models\WmsCrossDockOrder;
use Modules\Wms\Models\WmsDockAppointment;
use Modules\Wms\Models\WmsDockDoor;

/**
 * Dock doors. A door that still has live appointments or open cross-dock orders cannot be deleted.
 */
final class DockDoorService extends WmsCrudService
{
    protected function modelClass(): string
    {
        return WmsDockDoor::class;
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        /** @var WmsDockDoor|null $existing */
        if ($existing !== null && isset($data['direction']) && $data['direction'] !== $existing->direction && $data['direction'] !== WmsDockDoor::Both) {
            $conflicting = WmsDockAppointment::query()
                ->where('dock_door_id', $existing->id)
                ->whereIn('status', [WmsDockAppointment::Scheduled, WmsDockAppointment::CheckedIn])
                ->where('direction', '!=', $data['direction'])
                ->exists();

            if ($conflicting) {
                throw ValidationException::withMessages(['direction' => [__('wms_errors.dock.direction_in_use')]]);
            }
        }

        return $data;
    }

    protected function assertDeletable(Model $record): void
    {
        $live = WmsDockAppointment::query()
            ->where('dock_door_id', $record->getKey())
            ->whereIn('status', [WmsDockAppointment::Scheduled, WmsDockAppointment::CheckedIn])
            ->exists();
        $crossDock = WmsCrossDockOrder::query()
            ->whereIn('status', [WmsCrossDockOrder::Planned, WmsCrossDockOrder::Received])
            ->where(static fn ($query) => $query->where('inbound_door_id', $record->getKey())->orWhere('outbound_door_id', $record->getKey()))
            ->exists();

        if ($live || $crossDock) {
            throw ValidationException::withMessages(['id' => [__('wms_errors.dock.door_in_use')]]);
        }
    }
}
