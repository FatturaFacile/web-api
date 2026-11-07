<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsServiceDiscount;

/**
 * @extends Factory<CustomsServiceDiscount>
 */
final class CustomsServiceDiscountFactory extends Factory
{
    protected $model = CustomsServiceDiscount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'service_type' => 'road',
            'discount_percent' => fake()->randomElement(['5.00', '7.50', '10.00', '12.00']),
        ];
    }
}
