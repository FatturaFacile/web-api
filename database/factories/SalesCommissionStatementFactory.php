<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\SalesCommissionStatement;
use Modules\Invoicing\Models\SalesRepresentative;

/**
 * @extends Factory<SalesCommissionStatement>
 */
final class SalesCommissionStatementFactory extends Factory
{
    protected $model = SalesCommissionStatement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sales_representative_id' => static fn (array $attributes): string => (string) SalesRepresentative::create([
                'tenant_id' => $attributes['tenant_id'],
                'rep_code' => strtoupper(fake()->unique()->bothify('AG-####')),
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'territory' => fake()->randomElement(['Lombardia', 'Piemonte', 'Veneto', 'Ticino']),
                'default_commission_rate' => '5.00',
                'is_active' => true,
            ])->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'EUR',
            'total_base' => '24000.00',
            'total_commission' => '1200.00',
            'status' => SalesCommissionStatement::StatusDraft,
            'ledger_status' => SalesCommissionStatement::LedgerNotPosted,
        ];
    }
}
