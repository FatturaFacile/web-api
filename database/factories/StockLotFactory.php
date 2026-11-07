<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;

/**
 * @extends Factory<StockLot>
 */
class StockLotFactory extends Factory
{
    protected $model = StockLot::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'stock_item_id' => fn (array $attributes): string => StockItem::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'lot_number' => 'LOT-'.now()->format('Y').'-'.strtoupper(fake()->unique()->bothify('?###')),
            'manufacturing_date' => now()->subMonths(2)->toDateString(),
            'expiration_date' => now()->addYears(2)->toDateString(),
            'received_at' => now()->subMonth()->toDateString(),
            'is_quarantined' => false,
            'lot_level' => 'raw_material',
        ];
    }

    public function expiredLot(): static
    {
        return $this->state(fn () => [
            'expiration_date' => now()->subDay()->toDateString(),
            'status' => 'expired',
        ]);
    }

    public function quarantined(): static
    {
        return $this->state(fn () => ['status' => 'quarantined']);
    }
}
