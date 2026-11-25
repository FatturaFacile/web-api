<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Models;

use App\Models\Warehouse;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;

/**
 * Mobile van warehouse tied to a vehicle license plate (`targa`), assigned driver/salesperson,
 * central depot link, cold-chain temperature thresholds & log, and dedicated fiscal sectionals.
 *
 * @property string $code
 * @property string $name
 * @property string $license_plate
 * @property string|null $vehicle_make_model
 * @property string|null $warehouse_id
 * @property string|null $depot_warehouse_id
 * @property string|null $driver_user_id
 * @property string|null $driver_name
 * @property bool $has_cold_chain
 * @property string|null $min_temp_celsius
 * @property string|null $max_temp_celsius
 * @property string|null $current_temp_celsius
 * @property Carbon|null $last_temp_recorded_at
 * @property bool $temp_alert_active
 * @property string $invoice_sectional_code
 * @property int $invoice_next_number
 * @property string $ddt_sectional_code
 * @property int $ddt_next_number
 * @property string $receipt_sectional_code
 * @property int $receipt_next_number
 * @property int $fiscal_year
 * @property ThermalPrinterProfile $printer_profile
 * @property string $printer_brand
 * @property string|null $printer_mac_address
 * @property VanWarehouseStatus $status
 * @property array<int, array<string, mixed>>|null $current_stock_snapshot
 * @property array<int, array<string, mixed>>|null $empties_onboard_snapshot
 * @property array<int, array<string, mixed>>|null $temperature_log
 * @property int $sync_version
 * @property Carbon|null $last_synced_at
 * @property string|null $notes
 */
final class VanWarehouse extends BaseEnterpriseModel
{
    protected $table = 'van_warehouses';

    protected function casts(): array
    {
        return [
            'has_cold_chain' => 'boolean',
            'min_temp_celsius' => 'decimal:2',
            'max_temp_celsius' => 'decimal:2',
            'current_temp_celsius' => 'decimal:2',
            'last_temp_recorded_at' => 'datetime',
            'temp_alert_active' => 'boolean',
            'invoice_next_number' => 'integer',
            'ddt_next_number' => 'integer',
            'receipt_next_number' => 'integer',
            'fiscal_year' => 'integer',
            'printer_profile' => ThermalPrinterProfile::class,
            'status' => VanWarehouseStatus::class,
            'current_stock_snapshot' => 'array',
            'empties_onboard_snapshot' => 'array',
            'temperature_log' => 'array',
            'sync_version' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function depotWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'depot_warehouse_id');
    }

    /**
     * @return HasMany<VanLoadManifest, $this>
     */
    public function loadManifests(): HasMany
    {
        return $this->hasMany(VanLoadManifest::class, 'van_warehouse_id');
    }

    /**
     * @return HasMany<FieldRoutePlan, $this>
     */
    public function routePlans(): HasMany
    {
        return $this->hasMany(FieldRoutePlan::class, 'van_warehouse_id');
    }

    /**
     * @return HasMany<VanVisitOutcome, $this>
     */
    public function visitOutcomes(): HasMany
    {
        return $this->hasMany(VanVisitOutcome::class, 'van_warehouse_id');
    }

    /**
     * @return HasMany<VanEodSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(VanEodSettlement::class, 'van_warehouse_id');
    }
}
