<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

/**
 * One part of a split delivery.
 */
final class CustomsDossierDelivery extends CustomsRecord
{
    protected $table = 'customs_dossier_deliveries';

    protected function casts(): array
    {
        return ['gross_weight_kg' => 'decimal:3', 'delivered_on' => 'date'];
    }
}
