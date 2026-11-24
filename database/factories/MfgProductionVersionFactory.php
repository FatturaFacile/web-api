<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionVersion;
use Modules\Manufacturing\Models\MfgRouting;

/**
 * @extends Factory<MfgProductionVersion>
 */
final class MfgProductionVersionFactory extends Factory
{
    protected $model = MfgProductionVersion::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'version_code' => 'VER-'.strtoupper((string) Str::random(6)),
            'mfg_item_id' => MfgItem::factory(),
            'bom_id' => MfgBom::factory(),
            'routing_id' => MfgRouting::factory(),
            'min_lot_size' => 1.0000,
            'is_default' => true,
            'is_active' => true,
        ];
    }
}
