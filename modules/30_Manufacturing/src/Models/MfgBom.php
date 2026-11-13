<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A revision of the bill of materials of an item. Revisions share the BOM number; the one effective on a date is the
 * active (or superseded) revision whose effective range contains it. Draft revisions are editable and not effective.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $bom_number
 * @property string $mfg_item_id
 * @property string $name
 * @property string $revision
 * @property int $version
 * @property string $bom_type
 * @property string $status
 * @property string $base_quantity
 * @property string $uom
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_locked
 * @property string|null $notes
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $supersedes_bom_id
 * @property string|null $engineering_change_order_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgItem|null $mfgItem
 * @property-read Collection<int, MfgBomComponent> $components
 */
final class MfgBom extends BaseEnterpriseModel
{
    /** Statuses whose revision can be effective on a date. */
    public const array EffectiveStatuses = ['active', 'superseded'];

    protected $table = 'mfg_boms';

    /**
     * Calculated grid columns (see the GridColumns class of the grid engine).
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'components_count' => '(SELECT COUNT(*) FROM mfg_bom_components WHERE mfg_bom_components.bom_id = mfg_boms.id)',
        ];
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'base_quantity' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_locked' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return HasMany<MfgBomComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(MfgBomComponent::class, 'bom_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<MfgProductionVersion, $this>
     */
    public function productionVersions(): HasMany
    {
        return $this->hasMany(MfgProductionVersion::class, 'bom_id');
    }

    /**
     * Whether the revision is released for production.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether the revision is still a draft (editable, not effective).
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Whether the BOM is a phantom BOM.
     */
    public function isPhantom(): bool
    {
        return $this->bom_type === 'phantom';
    }
}
