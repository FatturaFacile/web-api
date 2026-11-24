<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\SupplierCostRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierCostRule>
 */
final class SupplierCostRuleFactory extends Factory
{
    protected $model = SupplierCostRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'supplier_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id'], 'type' => 'supplier'])->id,
            'keyword' => fake()->randomElement(['carburante', 'energia elettrica', 'canone software', 'manutenzione', 'cancelleria']),
            'cost_account_id' => static fn (array $attributes): string => (string) ChartOfAccountFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'type' => 'expense',
                'account_class' => 'expense',
                'account_type' => 'expense',
                'name' => 'Costi per servizi',
            ])->id,
        ];
    }
}
