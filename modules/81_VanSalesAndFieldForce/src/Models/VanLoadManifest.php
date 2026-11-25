<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\VanSalesAndFieldForce\Domain\Enums\LoadManifestStatus;

/**
 * Daily morning load manifest with historical weekday replenishment suggestions and
 * Global Van Load DDT ("Tentata Vendita" per DPR 472/1996 & CM 249/E).
 *
 * @property string $van_warehouse_id
 * @property string $manifest_number
 * @property Carbon $load_date
 * @property int $weekday_iso
 * @property string|null $route_code
 * @property string|null $depot_warehouse_id
 * @property string|null $global_ddt_id
 * @property string|null $global_ddt_number
 * @property string $global_ddt_causal
 * @property string $regulatory_reference
 * @property string|null $self_addressed_company_name
 * @property string|null $departure_temp_celsius
 * @property bool $cold_chain_compliant
 * @property LoadManifestStatus $status
 * @property array<int, array<string, mixed>> $lines
 * @property array<int, array<string, mixed>>|null $replenishment_suggestions
 * @property array<int, array<string, mixed>>|null $empties_loaded
 * @property int $total_packages
 * @property string $total_gross_weight_kg
 * @property string $total_net_value
 * @property string $currency
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $closed_at
 * @property string|null $created_by
 * @property string|null $notes
 */
final class VanLoadManifest extends BaseEnterpriseModel
{
    protected $table = 'van_load_manifests';

    protected function casts(): array
    {
        return [
            'load_date' => 'date',
            'weekday_iso' => 'integer',
            'departure_temp_celsius' => 'decimal:2',
            'cold_chain_compliant' => 'boolean',
            'status' => LoadManifestStatus::class,
            'lines' => 'array',
            'replenishment_suggestions' => 'array',
            'empties_loaded' => 'array',
            'total_packages' => 'integer',
            'total_gross_weight_kg' => 'decimal:3',
            'total_net_value' => 'decimal:2',
            'dispatched_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VanWarehouse, $this>
     */
    public function vanWarehouse(): BelongsTo
    {
        return $this->belongsTo(VanWarehouse::class, 'van_warehouse_id');
    }
}
