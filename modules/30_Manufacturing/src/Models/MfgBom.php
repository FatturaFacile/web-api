<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MfgBom extends BaseEnterpriseModel
{
    protected $table = 'mfg_boms';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'base_quantity' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_locked' => 'boolean',
        ];
    }

    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(MfgBomComponent::class, 'bom_id')->orderBy('sequence');
    }

    public function productionVersions(): HasMany
    {
        return $this->hasMany(MfgProductionVersion::class, 'bom_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPhantom(): bool
    {
        return $this->bom_type === 'phantom';
    }
}
