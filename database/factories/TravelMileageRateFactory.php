<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TravelExpense\Models\TravelMileageRate;

/**
 * @extends Factory<TravelMileageRate>
 */
final class TravelMileageRateFactory extends Factory
{
    protected $model = TravelMileageRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'jurisdiction' => 'IT',
            'year' => (int) now()->format('Y'),
            'vehicle_type' => 'car',
            'currency' => 'EUR',
            'rate_per_km' => '0.4000',
        ];
    }
}
