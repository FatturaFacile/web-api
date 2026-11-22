<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Raw material lot or parent base lot consumed by a lot.
 */
final class GelatoLotMaterialConsumption extends BaseEnterpriseModel
{
    protected $table = 'gelato_lot_material_consumptions';

    protected function casts(): array
    {
        return [
            'grams' => 'float',
        ];
    }
}
