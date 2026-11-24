<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\QuoteConfiguration;

/**
 * @extends Factory<QuoteConfiguration>
 */
final class QuoteConfigurationFactory extends Factory
{
    protected $model = QuoteConfiguration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'quantity' => '1.000',
            'unit_price' => '1000.0000',
            'currency' => 'EUR',
            'status' => 'draft',
        ];
    }
}
