<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MfgRouting extends BaseEnterpriseModel
{
    protected $table = 'mfg_routings';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(MfgOperation::class, 'routing_id')->orderBy('sequence');
    }

    public function productionVersions(): HasMany
    {
        return $this->hasMany(MfgProductionVersion::class, 'routing_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
