<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Ganache specification: chocolate profile, water activity, shelf life and tempering curve.
 */
final class GelatoChocolateGanacheSpec extends BaseEnterpriseModel
{
    protected $table = 'gelato_chocolate_ganache_specs';

    protected function casts(): array
    {
        return [
            'cocoa_butter_percent' => 'float',
            'cocoa_mass_percent' => 'float',
            'water_percent' => 'float',
            'water_activity' => 'float',
            'shelf_life_days' => 'integer',
            'tempering_curve' => 'array',
        ];
    }
}
