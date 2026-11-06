<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A groupage: several dossiers travelling on one carrier, sharing its freight cost.
 */
final class CustomsConsolidation extends CustomsRecord
{
    protected function casts(): array
    {
        return ['departure_on' => 'date', 'freight_cost' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    /**
     * @return HasMany<CustomsDossier, $this>
     */
    public function dossiers(): HasMany
    {
        return $this->hasMany(CustomsDossier::class, 'consolidation_id');
    }
}
