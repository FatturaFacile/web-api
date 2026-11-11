<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Inventory\Models\ShippingCarrier;

/**
 * @extends Factory<ShippingCarrier>
 */
final class ShippingCarrierFactory extends Factory
{
    protected $model = ShippingCarrier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CAR-###')),
            'name' => fake()->randomElement(['BRT Corriere Espresso', 'GLS Italy', 'Planzer Transport', 'Corriere locale']),
            'driver' => 'local',
            'contract_number' => fake()->numerify('CTR-########'),
            'credentials' => null,
            'tracking_url_template' => 'https://tracking.example.test/{tracking_number}',
            'is_active' => true,
        ];
    }

    /**
     * A carrier shared by every tenant.
     */
    public function generic(): self
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
