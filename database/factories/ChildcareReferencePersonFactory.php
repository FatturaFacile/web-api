<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\ReferencePerson;

/**
 * @extends Factory<ReferencePerson>
 */
final class ChildcareReferencePersonFactory extends Factory
{
    protected $model = ReferencePerson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'full_name' => fake()->firstNameFemale().' '.fake()->lastName(),
            'phone' => fake()->numerify('+41 79 ### ## ##'),
            'email' => fake()->unique()->safeEmail(),
            'relationship' => 'mother',
            'is_guardian' => true,
            'can_pick_up' => true,
            'portal_contact_id' => null,
        ];
    }
}
