<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A non-conformance raised by a failed inspection or by hand: severity, defect, affected quantity, disposition
 * (investigating, use_as_is, rework, scrap, return_to_supplier) and corrective action until it is closed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nc_number
 * @property string|null $quality_inspection_id
 * @property string|null $production_order_id
 * @property string|null $production_order_operation_id
 * @property string|null $mfg_item_id
 * @property string $severity
 * @property string $defect_type
 * @property string $description
 * @property string $quantity
 * @property string $disposition
 * @property string|null $corrective_action
 * @property string $status
 * @property string|null $reported_by
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgQualityNonConformance extends BaseEnterpriseModel
{
    protected $table = 'mfg_quality_non_conformances';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgQualityInspection, $this>
     */
    public function qualityInspection(): BelongsTo
    {
        return $this->belongsTo(MfgQualityInspection::class, 'quality_inspection_id');
    }

    /**
     * @return BelongsTo<MfgProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }
}
