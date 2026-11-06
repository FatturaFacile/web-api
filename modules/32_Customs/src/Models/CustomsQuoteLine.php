<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

final class CustomsQuoteLine extends CustomsRecord
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2'];
    }
}
