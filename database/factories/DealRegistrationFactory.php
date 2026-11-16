<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\DealRegistration;
use Modules\CRM\Models\Partner;

/**
 * @extends Factory<DealRegistration>
 */
final class DealRegistrationFactory extends Factory
{
    protected $model = DealRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'partner_id' => Partner::factory(),
            'customer_name' => fake()->company(),
            'customer_key' => Str::lower(Str::random(12)),
            'expected_amount' => '25000.00',
            'currency' => 'EUR',
            'status' => 'pending',
            'registered_on' => now()->toDateString(),
            'expires_on' => now()->addDays(90)->toDateString(),
        ];
    }
}
