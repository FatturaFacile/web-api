<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

final class VanWarehouseManagementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): VanWarehouse
    {
        return DB::transaction(function () use ($tenantId, $data): VanWarehouse {
            $code = strtoupper(trim((string) $data['code']));
            $plate = strtoupper(trim((string) $data['license_plate']));

            $physicalWarehouse = Warehouse::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => 'VAN-'.$code],
                [
                    'name' => (string) $data['name'].' ('.$plate.')',
                    'is_primary' => false,
                ]
            );

            $sectionalBase = preg_replace('/[^A-Z0-9]/', '', $code) ?: 'V01';

            return VanWarehouse::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $physicalWarehouse->id,
                'code' => $code,
                'name' => (string) $data['name'],
                'license_plate' => $plate,
                'vehicle_make_model' => $data['vehicle_make_model'] ?? null,
                'driver_user_id' => $data['driver_user_id'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'depot_warehouse_id' => $data['depot_warehouse_id'] ?? null,
                'has_cold_chain' => (bool) ($data['has_cold_chain'] ?? false),
                'min_temp_celsius' => $data['min_temp_celsius'] ?? null,
                'max_temp_celsius' => $data['max_temp_celsius'] ?? null,
                'current_temp_celsius' => $data['current_temp_celsius'] ?? null,
                'last_temp_recorded_at' => isset($data['current_temp_celsius']) ? now() : null,
                'invoice_sectional_code' => strtoupper((string) ($data['invoice_sectional_code'] ?? 'FV-'.$sectionalBase)),
                'ddt_sectional_code' => strtoupper((string) ($data['ddt_sectional_code'] ?? 'DDTV-'.$sectionalBase)),
                'receipt_sectional_code' => strtoupper((string) ($data['receipt_sectional_code'] ?? 'RTV-'.$sectionalBase)),
                'fiscal_year' => (int) ($data['fiscal_year'] ?? now()->year),
                'printer_profile' => ThermalPrinterProfile::from((string) ($data['printer_profile'] ?? ThermalPrinterProfile::EscPos80mm->value)),
                'printer_brand' => (string) ($data['printer_brand'] ?? 'Zebra'),
                'printer_mac_address' => $data['printer_mac_address'] ?? null,
                'status' => VanWarehouseStatus::from((string) ($data['status'] ?? VanWarehouseStatus::Active->value)),
                'current_stock_snapshot' => (array) ($data['current_stock_snapshot'] ?? []),
                'empties_onboard_snapshot' => (array) ($data['empties_onboard_snapshot'] ?? []),
                'temperature_log' => [],
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function recordTemperature(VanWarehouse $van, float $tempCelsius, ?string $notes = null): VanWarehouse
    {
        $log = (array) ($van->temperature_log ?? []);
        $log[] = [
            'recorded_at' => now()->toIso8601String(),
            'temp_celsius' => round($tempCelsius, 2),
            'notes' => $notes,
            'source' => 'manual_or_sensor',
        ];

        $alert = false;
        if ($van->has_cold_chain) {
            $min = $van->min_temp_celsius !== null ? (float) $van->min_temp_celsius : -30.0;
            $max = $van->max_temp_celsius !== null ? (float) $van->max_temp_celsius : 10.0;
            $alert = $tempCelsius < $min || $tempCelsius > $max;
        }

        $van->current_temp_celsius = (string) round($tempCelsius, 2);
        $van->last_temp_recorded_at = now();
        $van->temp_alert_active = $alert;
        $van->temperature_log = array_slice($log, -100);
        $van->sync_version = (int) $van->sync_version + 1;
        $van->save();

        return $van;
    }
}
