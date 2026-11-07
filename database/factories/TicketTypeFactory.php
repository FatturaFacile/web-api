<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\TicketType;

/**
 * @extends Factory<TicketType>
 */
final class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'course_id' => static fn (array $attributes): string => Course::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'name' => fake()->randomElement(['Intero', 'Ridotto soci', 'Under 18']),
            'price' => '15.00',
            'quantity' => 100,
            'is_active' => true,
        ];
    }
}
