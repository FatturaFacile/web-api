<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Enums\ShipmentRequestStatus;
use Modules\Customs\Models\CustomsShipmentRequest;

/**
 * @extends Factory<CustomsShipmentRequest>
 */
final class CustomsShipmentRequestFactory extends Factory
{
    protected $model = CustomsShipmentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => ShipmentRequestStatus::Pending,
            'service_type' => 'road',
            'direction' => 'export',
            'origin_country' => 'CH',
            'destination_country' => fake()->randomElement(['IT', 'DE', 'FR', 'AT']),
            'gross_weight_kg' => number_format(fake()->randomFloat(3, 10, 5000), 3, '.', ''),
            'volume_m3' => number_format(fake()->randomFloat(3, 0.1, 30), 3, '.', ''),
            'packages' => fake()->numberBetween(1, 24),
            'pickup_on' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'notes' => 'Ritiro presso la rampa di carico, orario 8-12.',
        ];
    }
}
