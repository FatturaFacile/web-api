<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Lead;

/**
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_name' => fake()->company(),
            'title' => fake()->jobTitle(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'website' => fake()->url(),
            'country' => 'IT',
            'status' => 'new',
            'score' => fake()->numberBetween(10, 60),
            'rating' => 'warm',
            'estimated_budget' => fake()->randomFloat(2, 5000, 50000),
            'tags' => ['inbound', 'manufacturing'],
            'custom_fields' => ['crm_source' => 'web_portal'],
        ];
    }

    public function newLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'new',
            'score' => 20,
            'rating' => 'cold',
        ]);
    }

    public function qualified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'qualified',
            'score' => 85,
            'rating' => 'hot',
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'converted',
            'converted_at' => now(),
            'score' => 90,
            'rating' => 'hot',
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disqualified',
            'disqualification_reason' => 'Budget constraints / competitor selection',
            'score' => 15,
            'rating' => 'cold',
        ]);
    }
}
