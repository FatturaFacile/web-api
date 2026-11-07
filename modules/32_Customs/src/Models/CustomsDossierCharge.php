<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Modules\Customs\Enums\ChargeCategory;
use Modules\Customs\Enums\ChargeKind;

final class CustomsDossierCharge extends CustomsRecord
{
    protected function casts(): array
    {
        return [
            'kind' => ChargeKind::class,
            'category' => ChargeCategory::class,
            'is_direct' => 'boolean',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }
}
