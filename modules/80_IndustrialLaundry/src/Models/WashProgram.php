<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A disinfection program of the wash line (thermal, chemical or chemo-thermal). The name is in the frontend dictionary under
 * industrialLaundry.program.<code>.
 *
 * @property string $code
 * @property bool $is_thermal
 * @property bool $is_chemical
 * @property bool $disinfects
 * @property string $target_temperature_c
 * @property int $min_hold_minutes
 * @property string|null $min_chemical_dosing_ml_per_kg
 * @property string|null $disinfectant_code
 * @property bool $is_active
 */
final class WashProgram extends BaseEnterpriseModel
{
    protected $table = 'ilr_wash_programs';

    protected function casts(): array
    {
        return [
            'is_thermal' => 'boolean',
            'is_chemical' => 'boolean',
            'disinfects' => 'boolean',
            'target_temperature_c' => 'decimal:2',
            'min_hold_minutes' => 'integer',
            'min_chemical_dosing_ml_per_kg' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
