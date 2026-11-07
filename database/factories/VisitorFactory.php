<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Visitors\Models\Visitor;

/**
 * @extends Factory<Visitor>
 */
final class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_name' => fake()->optional(0.7)->company(),
            'email' => fake()->safeEmail(),
            'phone' => '+39 0'.fake()->numerify('## #######'),
            'document_number' => null,
            'last_visit_at' => null,
        ];
    }
}
