<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Wms\Models\WmsCrossDockLine;
use Modules\Wms\Models\WmsCrossDockOrder;

/**
 * @extends Factory<WmsCrossDockLine>
 */
final class WmsCrossDockLineFactory extends Factory
{
    protected $model = WmsCrossDockLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'cross_dock_order_id' => WmsCrossDockOrder::factory(),
            'stock_item_id' => Str::uuid7()->toString(),
            'inbound_quantity' => '1.00',
            'received_quantity' => '0',
            'outbound_quantity' => '0',
        ];
    }
}
