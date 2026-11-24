<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Plm\Models\PlmAffectedBom;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * @extends Factory<PlmAffectedBom>
 */
final class PlmAffectedBomFactory extends Factory
{
    protected $model = PlmAffectedBom::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'change_order_id' => PlmChangeOrder::factory(),
            'bom_id' => MfgBom::factory(),
        ];
    }
}
