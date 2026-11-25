<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Modules\IndustrialLaundry\Models\WashProgram;

/**
 * The disinfection programs of the wash line. The standard set is stored per company the first time it is needed (or by the
 * reference seeder); the names are in the frontend dictionary under industrialLaundry.program.<code>.
 */
final class WashProgramCatalog
{
    /**
     * @return list<array{code: string, is_thermal: bool, is_chemical: bool, disinfects: bool, target_temperature_c: string, min_hold_minutes: int, min_chemical_dosing_ml_per_kg: string|null, disinfectant_code: string|null}>
     */
    public static function defaults(): array
    {
        return [
            ['code' => 'STANDARD_60', 'is_thermal' => true, 'is_chemical' => false, 'disinfects' => false, 'target_temperature_c' => '60.00', 'min_hold_minutes' => 10, 'min_chemical_dosing_ml_per_kg' => null, 'disinfectant_code' => null],
            ['code' => 'THERMAL_71_3MIN', 'is_thermal' => true, 'is_chemical' => false, 'disinfects' => true, 'target_temperature_c' => '71.00', 'min_hold_minutes' => 3, 'min_chemical_dosing_ml_per_kg' => null, 'disinfectant_code' => null],
            ['code' => 'THERMAL_85_15MIN', 'is_thermal' => true, 'is_chemical' => false, 'disinfects' => true, 'target_temperature_c' => '85.00', 'min_hold_minutes' => 15, 'min_chemical_dosing_ml_per_kg' => null, 'disinfectant_code' => null],
            ['code' => 'CHEMOTHERMAL_60_PAA', 'is_thermal' => true, 'is_chemical' => true, 'disinfects' => true, 'target_temperature_c' => '60.00', 'min_hold_minutes' => 10, 'min_chemical_dosing_ml_per_kg' => '25.000', 'disinfectant_code' => 'peracetic_acid'],
            ['code' => 'CHEMICAL_40_PAA', 'is_thermal' => false, 'is_chemical' => true, 'disinfects' => true, 'target_temperature_c' => '40.00', 'min_hold_minutes' => 10, 'min_chemical_dosing_ml_per_kg' => '35.000', 'disinfectant_code' => 'peracetic_acid'],
        ];
    }

    public function ensure(string $tenantId): void
    {
        if (WashProgram::query()->exists()) {
            return;
        }

        foreach (self::defaults() as $program) {
            WashProgram::query()->firstOrCreate(['tenant_id' => $tenantId, 'code' => $program['code']], $program + ['is_active' => true]);
        }
    }
}
