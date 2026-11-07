<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

final class CustomsServiceDiscount extends CustomsRecord
{
    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2'];
    }
}
