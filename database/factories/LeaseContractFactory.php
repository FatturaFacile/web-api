<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\LeaseAccounting\Models\LeaseContract;

/**
 * @extends Factory<LeaseContract>
 */
final class LeaseContractFactory extends Factory
{
    protected $model = LeaseContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'LS-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(3),
            'lessor_name' => fake()->company(),
            'asset_class' => 'real_estate',
            'accounting_standard' => 'ifrs16',
            'currency' => 'EUR',
            'commencement_date' => '2027-01-01',
            'term_months' => 12,
            'payment_amount' => '1000.00',
            'payment_frequency' => 'monthly',
            'payment_timing' => 'arrears',
            'escalation_type' => 'none',
            'escalation_rate_percent' => '0',
            'incremental_borrowing_rate_percent' => '4.800000',
            'initial_direct_costs' => '0',
            'incentives_received' => '0',
            'purchase_option_reasonably_certain' => false,
            'extension_reasonably_certain' => false,
            'exemption' => 'none',
            'status' => 'draft',
            'current_version_number' => 0,
        ];
    }

    public function advance(): self
    {
        return $this->state(['payment_timing' => 'advance']);
    }

    public function swiss(): self
    {
        return $this->state(['currency' => 'CHF', 'accounting_standard' => 'swiss_gaap_fer']);
    }
}
