<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * @extends Factory<TravelTrip>
 */
final class TravelTripFactory extends Factory
{
    protected $model = TravelTrip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(5, 60))->startOfDay();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'TR-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'user_id' => User::factory(),
            'purpose' => fake()->sentence(4),
            'destination_city' => fake()->city(),
            'destination_country' => 'IT',
            'start_date' => $start->toDateString(),
            'end_date' => $start->addDays(2)->toDateString(),
            'currency' => 'EUR',
            'rate_jurisdiction' => 'IT',
            'estimated_budget' => '600.00',
            'advance_amount' => '0.00',
            'status' => TravelTrip::Draft,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function swiss(): static
    {
        return $this->state(['destination_country' => 'CH', 'currency' => 'CHF', 'rate_jurisdiction' => 'CH']);
    }
}
