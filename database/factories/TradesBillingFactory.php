<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\Billing;

/**
 * @extends Factory<Billing>
 */
final class TradesBillingFactory extends Factory
{
    protected $model = Billing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grossThis = fake()->randomFloat(2, 2000, 20000);
        $retention = round($grossThis * 0.05, 2);

        return [
            'tenant_id' => Tenant::factory(),
            'bid_id' => static fn (array $attributes): string => (string) TradesBidFactory::new()->create(['tenant_id' => $attributes['tenant_id'], 'status' => 'accepted'])->id,
            'kind' => 'partial',
            'basis' => 'percentage',
            'sequence' => 1,
            'billing_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'percentage' => '30.0000',
            'gross_to_date' => number_format($grossThis, 2, '.', ''),
            'previous_gross' => '0.00',
            'gross_this' => number_format($grossThis, 2, '.', ''),
            'retention_amount' => number_format($retention, 2, '.', ''),
            'amount_invoiced' => number_format($grossThis - $retention, 2, '.', ''),
        ];
    }
}
