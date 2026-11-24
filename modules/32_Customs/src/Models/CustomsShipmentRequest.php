<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Modules\Customs\Enums\ShipmentRequestStatus;

/**
 * A shipment asked for by a customer through the portal.
 */
final class CustomsShipmentRequest extends CustomsRecord
{
    protected function casts(): array
    {
        return [
            'status' => ShipmentRequestStatus::class,
            'gross_weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:3',
            'pickup_on' => 'date',
        ];
    }
}
