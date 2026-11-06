<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomsTariffGrid extends CustomsRecord
{
    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<CustomsTariffGridRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(CustomsTariffGridRate::class, 'tariff_grid_id');
    }
}
