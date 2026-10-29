<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PriceBook;

class PriceBookFactory extends Factory
{
    protected $model = PriceBook::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'PB-'.strtoupper(Str::random(6)),
            'name' => fake()->words(2, true).' Price Book',
            'currency' => 'EUR',
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
