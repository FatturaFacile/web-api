<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A confirmed configuration. Identical configurations of the same model (same version, same normalised selections)
 * share one instance and one part number: `configuration_hash` is the SHA-256 of the canonical selections.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property int $model_version
 * @property string $configuration_hash
 * @property string $part_number
 * @property array<string, mixed> $configuration
 * @property array<string, mixed> $snapshot
 * @property string $currency
 * @property string $industrial_cost
 * @property string $list_price
 * @property string $selling_price
 * @property string $margin_amount
 * @property string $margin_percent
 * @property int $lead_time_days
 * @property string $status
 * @property string|null $mfg_item_id
 * @property string|null $mfg_bom_id
 * @property string|null $customer_reference
 * @property int $reuse_count
 * @property string|null $created_by
 * @property Carbon|null $confirmed_at
 */
final class CpqConfiguredInstance extends BaseEnterpriseModel
{
    public const string Confirmed = 'confirmed';

    public const string Archived = 'archived';

    protected $table = 'cpq_configured_instances';

    protected function casts(): array
    {
        return [
            'model_version' => 'integer',
            'configuration' => 'array',
            'snapshot' => 'array',
            'industrial_cost' => 'decimal:4',
            'list_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'margin_amount' => 'decimal:4',
            'margin_percent' => 'decimal:2',
            'lead_time_days' => 'integer',
            'reuse_count' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CpqModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(CpqModel::class, 'model_id');
    }
}
