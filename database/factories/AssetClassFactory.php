<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetClass;

/**
 * @extends Factory<AssetClass>
 */
final class AssetClassFactory extends Factory
{
    protected $model = AssetClass::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CLS-'.strtoupper(Str::random(6)),
            'name' => 'Industrial Equipment',
            'criticality' => 'high',
            'default_maintenance_interval_days' => 90,
            'requires_custodian' => true,
            'requires_calibration' => false,
            'is_active' => true,
            'translations' => [
                'it' => 'Attrezzature Industriali',
                'en' => 'Industrial Equipment',
                'de' => 'Industrieausrüstung',
                'fr' => 'Équipement industriel',
            ],
        ];
    }
}
