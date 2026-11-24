<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Hospitality\Models\Order;

/**
 * @extends Factory<Order>
 */
final class HospitalityOrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'table_id' => static fn (array $attributes): string => (string) HospitalityDiningTableFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'covers' => fake()->numberBetween(1, 6),
            'status' => 'open',
            'opened_by' => null,
            'closed_at' => null,
            'notes' => null,
        ];
    }
}
