<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Collection;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Models\DistributionLane;
use Modules\Distribution\Models\WarehouseProfile;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\Warehouse;

/**
 * The warehouse network: the role of every warehouse, its supplying hub, the lanes between warehouses and the guards a transfer
 * must respect (blocked or counting warehouses).
 */
final class NetworkService
{
    /**
     * Role of each warehouse: the profile when there is one, otherwise derived from the legacy warehouse type.
     *
     * @param  Collection<int, Warehouse>|list<Warehouse>  $warehouses
     * @return array<string, WarehouseRole>
     */
    public function roles(Collection|array $warehouses): array
    {
        $warehouses = collect($warehouses);
        $profiles = WarehouseProfile::query()->whereIn('warehouse_id', $warehouses->pluck('id')->all())->get()->keyBy('warehouse_id');
        $roles = [];

        foreach ($warehouses as $warehouse) {
            $roles[(string) $warehouse->id] = $profiles->get($warehouse->id)?->role ?? $this->derivedRole($warehouse);
        }

        return $roles;
    }

    public function roleOf(Warehouse $warehouse): WarehouseRole
    {
        return $this->roles([$warehouse])[(string) $warehouse->id];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(Warehouse $warehouse, array $data): WarehouseProfile
    {
        $role = WarehouseRole::from((string) ($data['role'] ?? WarehouseRole::Internal->value));

        // Consignment and 3PL stock is segregated from the free stock unless the profile says otherwise.
        $defaults = [
            'include_in_free_stock' => $role->isPlannable(),
            'counts_in_valuation' => $role !== WarehouseRole::ConsignmentIn,
        ];

        $profile = WarehouseProfile::query()->firstOrNew(['warehouse_id' => $warehouse->id]);

        if (! $profile->exists) {
            $profile->fill($defaults);
        }

        $profile->fill($data + ['role' => $role->value])->save();

        return $profile->refresh();
    }

    /**
     * The fastest active lane from a warehouse to another, if one is defined.
     */
    public function laneBetween(string $sourceId, string $destinationId): ?DistributionLane
    {
        return DistributionLane::query()
            ->where('source_warehouse_id', $sourceId)
            ->where('destination_warehouse_id', $destinationId)
            ->where('is_active', true)
            ->orderBy('lead_time_days')
            ->orderBy('lead_time_hours')
            ->first();
    }

    /**
     * Refuses a transfer from or to a warehouse that cannot take part: inactive, blocked, or being counted at the source.
     */
    public function assertTransferable(Warehouse $source, Warehouse $destination): void
    {
        foreach ([$source, $destination] as $warehouse) {
            if (! $warehouse->is_active) {
                throw new StockRuleViolation("Warehouse [{$warehouse->name}] is not active.");
            }
        }

        $profiles = WarehouseProfile::query()->whereIn('warehouse_id', [$source->id, $destination->id])->get()->keyBy('warehouse_id');

        foreach ([$source, $destination] as $warehouse) {
            if ($profiles->get($warehouse->id)?->is_blocked === true) {
                throw new StockRuleViolation("Warehouse [{$warehouse->name}] is blocked for transfers.");
            }
        }

        if ($profiles->get($source->id)?->is_counting === true) {
            throw new StockRuleViolation("Warehouse [{$source->name}] is being counted: stock cannot leave it.");
        }
    }

    private function derivedRole(Warehouse $warehouse): WarehouseRole
    {
        return match ((string) $warehouse->warehouse_type) {
            'transit' => WarehouseRole::Transit,
            'customer_consignment' => WarehouseRole::ConsignmentOut,
            'vendor_consignment' => WarehouseRole::ConsignmentIn,
            default => WarehouseRole::Internal,
        };
    }
}
