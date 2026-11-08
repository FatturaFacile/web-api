<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A routing revision of an item: the sequence of operations on work centres with their setup and run times.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $routing_number
 * @property string $mfg_item_id
 * @property string $name
 * @property string $revision
 * @property string $status
 * @property bool $is_default
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MfgOperation> $operations
 */
final class MfgRouting extends BaseEnterpriseModel
{
    protected $table = 'mfg_routings';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
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
     * @return HasMany<MfgOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(MfgOperation::class, 'routing_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<MfgProductionVersion, $this>
     */
    public function productionVersions(): HasMany
    {
        return $this->hasMany(MfgProductionVersion::class, 'routing_id');
    }

    /**
     * Whether the routing is released for production.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
