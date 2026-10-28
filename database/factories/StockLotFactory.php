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
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'stock_item_id' => StockItem::factory(),
            'lot_number' => 'LOT-'.now()->format('Y').'-'.strtoupper(fake()->unique()->bothify('?###')),
            'manufacture_date' => now()->subMonths(2)->toDateString(),
            'expiration_date' => now()->addYears(2)->toDateString(),
            'received_at' => now()->subMonths(1),
            'initial_quantity' => 100.0,
            'current_quantity' => 100.0,
            'status' => 'active',
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
