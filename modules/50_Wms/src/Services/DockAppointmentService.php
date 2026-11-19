<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Wms\Models\WmsCrossDockOrder;
use Modules\Wms\Models\WmsDockAppointment;
use Modules\Wms\Models\WmsDockDoor;

/**
 * Dock appointments. A door takes one carrier at a time: an appointment that overlaps a scheduled or checked-in
 * appointment of the same door is refused, and so is a door that is inactive or does not serve the direction. The check
 * runs under a lock on the door so two bookings never slip in together.
 */
final class DockAppointmentService extends WmsCrudService
{
    /** Statuses that keep a door busy. */
    private const array Busy = [WmsDockAppointment::Scheduled, WmsDockAppointment::CheckedIn];

    protected function modelClass(): string
    {
        return WmsDockAppointment::class;
    }

    public function create(string $tenantId, array $data): Model
    {
        return DB::transaction(function () use ($tenantId, $data): Model {
            TransactionLock::acquire("wms:dock:{$data['dock_door_id']}");

            return parent::create($tenantId, $data);
        });
    }

    public function update(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            TransactionLock::acquire("wms:dock:{$record->getAttribute('dock_door_id')}");

            return parent::update($record, $data);
        });
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        /** @var WmsDockAppointment|null $existing */
        if ($existing !== null && $existing->status !== WmsDockAppointment::Scheduled) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.dock.not_editable', ['status' => $existing->status])]]);
        }

        $doorId = (string) ($existing->dock_door_id ?? $data['dock_door_id']);
        $door = WmsDockDoor::query()->where('tenant_id', $tenantId)->findOrFail($doorId);
        $direction = (string) ($data['direction'] ?? $existing?->direction);
        $start = Carbon::parse((string) ($data['starts_at'] ?? $existing?->starts_at));
        $end = Carbon::parse((string) ($data['ends_at'] ?? $existing?->ends_at));

        if (! $door->is_active) {
            throw ValidationException::withMessages(['dock_door_id' => [__('wms_errors.dock.door_inactive', ['door' => $door->code])]]);
        }

        if (! in_array($door->direction, [$direction, WmsDockDoor::Both], true)) {
            throw ValidationException::withMessages(['direction' => [__('wms_errors.dock.door_direction', ['door' => $door->code, 'direction' => $direction])]]);
        }

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => [__('wms_errors.dock.ends_before_start')]]);
        }

        $clash = WmsDockAppointment::query()
            ->where('dock_door_id', $door->id)
            ->whereIn('status', self::Busy)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($existing !== null, static fn ($query) => $query->where('id', '!=', $existing?->id))
            ->orderBy('starts_at')
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages(['starts_at' => [__('wms_errors.dock.conflict', [
                'door' => $door->code,
                'from' => $clash->starts_at->format('Y-m-d H:i'),
                'to' => $clash->ends_at->format('Y-m-d H:i'),
            ])]]);
        }

        if (($data['cross_dock_order_id'] ?? null) !== null) {
            $order = WmsCrossDockOrder::query()->where('tenant_id', $tenantId)->find($data['cross_dock_order_id']);

            if ($order === null || $order->warehouse_id !== $door->warehouse_id) {
                throw ValidationException::withMessages(['cross_dock_order_id' => [__('wms_errors.dock.cross_dock_other_warehouse')]]);
            }
        }

        return [...$data, 'warehouse_id' => $door->warehouse_id];
    }

    protected function assertDeletable(Model $record): void
    {
        /** @var WmsDockAppointment $record */
        if (in_array($record->status, [WmsDockAppointment::CheckedIn, WmsDockAppointment::Completed], true)) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.dock.not_deletable', ['status' => $record->status])]]);
        }
    }

    public function checkIn(WmsDockAppointment $appointment): WmsDockAppointment
    {
        return $this->transition($appointment, [WmsDockAppointment::Scheduled], ['status' => WmsDockAppointment::CheckedIn, 'checked_in_at' => now()]);
    }

    public function complete(WmsDockAppointment $appointment): WmsDockAppointment
    {
        return $this->transition($appointment, [WmsDockAppointment::CheckedIn], ['status' => WmsDockAppointment::Completed, 'completed_at' => now()]);
    }

    public function cancel(WmsDockAppointment $appointment): WmsDockAppointment
    {
        return $this->transition($appointment, [WmsDockAppointment::Scheduled], ['status' => WmsDockAppointment::Cancelled]);
    }

    public function noShow(WmsDockAppointment $appointment): WmsDockAppointment
    {
        if ($appointment->starts_at->isFuture()) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.dock.no_show_too_early')]]);
        }

        return $this->transition($appointment, [WmsDockAppointment::Scheduled], ['status' => WmsDockAppointment::NoShow]);
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(WmsDockAppointment $appointment, array $from, array $changes): WmsDockAppointment
    {
        return DB::transaction(function () use ($appointment, $from, $changes): WmsDockAppointment {
            $locked = WmsDockAppointment::query()->lockForUpdate()->findOrFail($appointment->id);

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => [__('wms_errors.dock.invalid_transition', ['status' => $locked->status])]]);
            }

            $locked->update($changes);

            return $locked->refresh()->load('door:id,code,name,warehouse_id');
        });
    }
}
