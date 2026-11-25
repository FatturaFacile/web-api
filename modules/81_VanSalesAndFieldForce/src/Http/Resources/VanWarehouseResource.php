<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * @mixin VanWarehouse
 */
final class VanWarehouseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'license_plate' => $this->license_plate,
            'vehicle_make_model' => $this->vehicle_make_model,
            'warehouse_id' => $this->warehouse_id,
            'depot_warehouse_id' => $this->depot_warehouse_id,
            'driver_user_id' => $this->driver_user_id,
            'driver_name' => $this->driver_name,
            'has_cold_chain' => $this->has_cold_chain,
            'min_temp_celsius' => $this->min_temp_celsius,
            'max_temp_celsius' => $this->max_temp_celsius,
            'current_temp_celsius' => $this->current_temp_celsius,
            'last_temp_recorded_at' => $this->last_temp_recorded_at?->toIso8601String(),
            'temp_alert_active' => $this->temp_alert_active,
            'invoice_sectional_code' => $this->invoice_sectional_code,
            'invoice_next_number' => $this->invoice_next_number,
            'ddt_sectional_code' => $this->ddt_sectional_code,
            'ddt_next_number' => $this->ddt_next_number,
            'receipt_sectional_code' => $this->receipt_sectional_code,
            'receipt_next_number' => $this->receipt_next_number,
            'fiscal_year' => $this->fiscal_year,
            'printer_profile' => $this->printer_profile->value,
            'printer_brand' => $this->printer_brand,
            'printer_mac_address' => $this->printer_mac_address,
            'status' => $this->status->value,
            'current_stock_snapshot' => $this->current_stock_snapshot ?? [],
            'empties_onboard_snapshot' => $this->empties_onboard_snapshot ?? [],
            'temperature_log' => $this->temperature_log ?? [],
            'sync_version' => $this->sync_version,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
