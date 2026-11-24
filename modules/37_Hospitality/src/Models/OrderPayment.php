<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

/**
 * A part of the bill of an order, registered as a POS sale.
 */
final class OrderPayment extends HospitalityRecord
{
    protected $table = 'hosp_order_payments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'lines' => 'array'];
    }
}
