<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\Donor;

/**
 * @extends Factory<Donation>
 */
final class DonationFactory extends Factory
{
    protected $model = Donation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'donor_id' => static fn (array $attributes): string => Donor::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'campaign_id' => null,
            'kind' => Donation::Money,
            'amount' => number_format(fake()->randomFloat(2, 10, 2000), 2, '.', ''),
            'received_on' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'payment_method' => 'bank',
            'purpose' => 'Erogazione liberale per le attività istituzionali',
            'status' => 'recorded',
        ];
    }

    public function inKind(): static
    {
        return $this->state(['kind' => Donation::InKind, 'payment_method' => null, 'amount' => '250.00', 'in_kind_description' => 'Dieci palloni da pallavolo']);
    }
}
