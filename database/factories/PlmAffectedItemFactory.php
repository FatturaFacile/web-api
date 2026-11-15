<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Plm\Models\PlmAffectedItem;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * @extends Factory<PlmAffectedItem>
 */
final class PlmAffectedItemFactory extends Factory
{
    protected $model = PlmAffectedItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'change_order_id' => PlmChangeOrder::factory(),
            'mfg_item_id' => MfgItem::factory(),
            'to_revision' => 'B',
        ];
    }
}
