<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Modules\Customs\Enums\RateBasis;

final class CustomsTariffGridRate extends CustomsRecord
{
    protected function casts(): array
    {
        return [
            'basis' => RateBasis::class,
            'min_weight_kg' => 'decimal:3',
            'max_weight_kg' => 'decimal:3',
            'min_volume_m3' => 'decimal:3',
            'max_volume_m3' => 'decimal:3',
            'rate' => 'decimal:4',
            'minimum_charge' => 'decimal:2',
        ];
    }
}
