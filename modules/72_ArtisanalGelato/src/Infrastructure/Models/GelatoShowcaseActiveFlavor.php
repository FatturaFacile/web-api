<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Flavor assigned to a showcase well (pozzetto).
 */
final class GelatoShowcaseActiveFlavor extends BaseEnterpriseModel
{
    protected $table = 'gelato_showcase_active_flavors';

    protected function casts(): array
    {
        return [
            'slot_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
