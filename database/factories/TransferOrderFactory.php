<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\TransferOrder;

/**
 * A draft transfer order between two warehouses of the same company.
 *
 * @extends Factory<TransferOrder>
 */
final class TransferOrderFactory extends Factory
{
    protected $model = TransferOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'transfer_number' => 'TO-'.fake()->unique()->numerify('######'),
            'source_warehouse_id' => static fn (array $attributes): string => (string) WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'destination_warehouse_id' => static fn (array $attributes): string => (string) WarehouseFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => 'draft',
        ];
    }
}
