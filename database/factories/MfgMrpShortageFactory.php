<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;

/**
 * @extends Factory<MfgMrpShortage>
 */
final class MfgMrpShortageFactory extends Factory
{
    protected $model = MfgMrpShortage::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'mrp_run_id' => MfgMrpRun::factory(),
            'mfg_item_id' => MfgItem::factory(),
            'required_date' => now()->addDays(7)->toDateString(),
            'gross_requirement' => 100.0000,
            'available_inventory' => 20.0000,
            'scheduled_receipts' => 0.0000,
            'safety_stock' => 10.0000,
            'net_shortage' => 90.0000,
            'recommended_action' => 'purchase_order',
        ];
    }
}
