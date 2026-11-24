<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovObligation;

/**
 * @extends Factory<GovObligation>
 */
final class GovObligationFactory extends Factory
{
    protected $model = GovObligation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'framework' => fake()->randomElement(GovObligation::Frameworks),
            'jurisdiction' => fake()->randomElement(GovObligation::Jurisdictions),
            'title' => fake()->sentence(4),
            'recurrence' => 'annual',
            'due_date' => fake()->dateTimeBetween('+1 week', '+6 months')->format('Y-m-d'),
            'reminder_days' => 30,
            'status' => 'pending',
        ];
    }
}
