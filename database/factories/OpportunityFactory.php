<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

/**
 * @extends Factory<Opportunity>
 */
final class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 5000, 75000);
        $probability = 20.00;

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'account_id' => Account::factory(),
            'stage_id' => OpportunityStage::factory(),
            'opportunity_number' => 'OPP-'.date('Ymd').'-'.strtoupper(Str::random(4)),
            'name' => fake()->catchPhrase().' Solution',
            'amount' => $amount,
            'expected_revenue' => round(($amount * $probability) / 100.0, 2),
            'currency' => 'EUR',
            'probability' => $probability,
            'forecast_category' => 'pipeline',
            'expected_close_date' => now()->addDays(30)->toDateString(),
            'status' => 'open',
            'approval_status' => 'not_required',
        ];
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'won',
            'probability' => 100.00,
            'expected_revenue' => $attributes['amount'] ?? 50000.00,
            'forecast_category' => 'closed',
            'actual_close_date' => now()->toDateString(),
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'lost',
            'probability' => 0.00,
            'expected_revenue' => 0.00,
            'forecast_category' => 'omitted',
            'win_loss_reason' => 'Lost to competitor on pricing',
            'actual_close_date' => now()->toDateString(),
        ]);
    }

    public function forecasted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'probability' => 80.00,
            'forecast_category' => 'commit',
            'expected_revenue' => round((($attributes['amount'] ?? 40000.00) * 0.8), 2),
        ]);
    }

    public function stalled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'expected_close_date' => now()->subDays(45)->toDateString(),
            'forecast_category' => 'pipeline',
        ]);
    }
}
