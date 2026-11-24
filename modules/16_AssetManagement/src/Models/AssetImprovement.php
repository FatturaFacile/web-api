<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Direct capital improvement / extraordinary capitalized maintenance applied to a fixed asset.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $asset_id
 * @property string $improvement_number
 * @property Carbon $improvement_date
 * @property string $amount
 * @property string $description
 * @property string|null $supplier_id
 * @property string|null $purchase_invoice_id
 * @property string|null $purchase_invoice_number
 * @property int $extends_useful_life_months
 * @property bool $affects_tax_book
 * @property string $status
 * @property string|null $journal_entry_id
 * @property string|null $created_by_user_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssetImprovement extends BaseEnterpriseModel
{
    protected $table = 'eam_asset_improvements';

    protected function casts(): array
    {
        return [
            'improvement_date' => 'date',
            'amount' => 'decimal:2',
            'extends_useful_life_months' => 'integer',
            'affects_tax_book' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
