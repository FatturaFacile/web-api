<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgAccountingSetting;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Support\Money;

/**
 * Master data of the module: items (linked to the inventory stock item and catalog product), work centres with their
 * rates, and the ledger accounts of the tenant.
 */
final readonly class ManufacturingMasterDataService
{
    /** Item fields stored as decimal strings with 4 decimals. */
    private const array ItemDecimals = ['standard_cost', 'safety_stock', 'min_lot_size', 'max_lot_size', 'lot_size_multiple'];

    /**
     * Creates an item; the catalog product is taken from the stock item when not given.
     *
     * @param  array<string, mixed>  $data
     */
    public function createItem(string $tenantId, array $data): MfgItem
    {
        return MfgItem::create([...$this->itemAttributes($tenantId, $data), 'tenant_id' => $tenantId, 'is_active' => (bool) ($data['is_active'] ?? true)]);
    }

    /**
     * Updates an item; the standard cost breakdown is changed only by the cost rollup.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateItem(MfgItem $item, array $data): MfgItem
    {
        $item->update($this->itemAttributes((string) $item->tenant_id, $data));

        return $item->refresh();
    }

    /**
     * Creates a work centre.
     *
     * @param  array<string, mixed>  $data
     */
    public function createWorkCenter(string $tenantId, array $data): MfgWorkCenter
    {
        return MfgWorkCenter::create([...$this->workCenterAttributes($data), 'tenant_id' => $tenantId, 'status' => $data['status'] ?? 'active']);
    }

    /**
     * Updates a work centre; new rates apply to orders released from now on.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateWorkCenter(MfgWorkCenter $workCenter, array $data): MfgWorkCenter
    {
        $workCenter->update($this->workCenterAttributes($data));

        return $workCenter->refresh();
    }

    /**
     * The accounting settings of the tenant, a new unsaved row when none exists.
     */
    public function accountingSettings(string $tenantId): MfgAccountingSetting
    {
        return MfgAccountingSetting::query()->where('tenant_id', $tenantId)->first() ?? new MfgAccountingSetting(['tenant_id' => $tenantId]);
    }

    /**
     * Stores the account codes of the tenant (an empty code goes back to the module default).
     *
     * @param  array<string, string|null>  $codes  role => account code
     */
    public function updateAccountingSettings(MfgAccountingSetting $settings, array $codes, ?string $actorUserId): MfgAccountingSetting
    {
        return DB::transaction(function () use ($settings, $codes, $actorUserId): MfgAccountingSetting {
            foreach (MfgAccountingSetting::AccountRoles as $role) {
                if (array_key_exists($role.'_account_code', $codes)) {
                    $value = $codes[$role.'_account_code'];
                    $settings->setAttribute($role.'_account_code', $value !== null && $value !== '' ? $value : null);
                }
            }
            $settings->setAttribute('updated_by', $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null);
            $settings->save();

            return $settings->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function itemAttributes(string $tenantId, array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['item_code', 'name', 'description', 'item_type', 'procurement_type', 'stock_item_id', 'product_id', 'default_warehouse_id', 'uom', 'lead_time_days', 'is_active', 'scrap_rate']));

        foreach (self::ItemDecimals as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] !== null ? Money::round((string) $data[$field], 4) : null;
            }
        }

        if (isset($attributes['stock_item_id']) && ! isset($attributes['product_id'])) {
            $stockItem = StockItem::query()->where('tenant_id', $tenantId)->find($attributes['stock_item_id']);
            if (! $stockItem instanceof StockItem) {
                throw new ManufacturingOperationException('The stock item does not belong to the company.', 'stock_item_id');
            }
            $attributes['product_id'] = $stockItem->product_id;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function workCenterAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['work_center_code', 'name', 'description', 'department_code', 'status']));
        foreach (['capacity_hours_per_day' => 2, 'efficiency_rate' => 2, 'availability_rate' => 2, 'hourly_cost_rate' => 4, 'hourly_overhead_rate' => 4] as $field => $decimals) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $attributes[$field] = Money::round((string) $data[$field], $decimals);
            }
        }

        return $attributes;
    }
}
