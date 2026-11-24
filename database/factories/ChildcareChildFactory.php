<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\Child;

/**
 * @extends Factory<Child>
 */
class ChildcareChildFactory extends Factory
{
    protected $model = Child::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'billing_contact_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'reference_code' => strtoupper(fake()->unique()->bothify('CHD-#####')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-5 years', '-1 year')->format('Y-m-d'),
            'enrolled_on' => '2026-01-01',
            'status' => 'active',
        ];
    }
}
