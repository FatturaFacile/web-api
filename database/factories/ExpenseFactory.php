<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $category = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'expense_category'))->first();
        $net = round(fake()->randomFloat(2, 5, 250), 2);
        $taxRate = 22.00;
        $taxAmount = round($net * ($taxRate / 100), 2);
        $gross = round($net + $taxAmount, 2);

        return [
            'tenant_id' => $tenantId,
            'expense_number' => 'EXP-'.fake()->unique()->numerify('2026-####'),
            'category_id' => $category?->id ?? LookupItem::first()?->id,
            'date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'amount_net' => $net,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'amount_gross' => $gross,
            'currency' => 'EUR',
            'merchant_name' => fake()->company(),
            'payment_method' => fake()->randomElement(['cash', 'credit_card', 'company_card', 'bank_transfer']),
            'status' => 'submitted',
            'is_billable' => fake()->boolean(30),
            'is_reimbursable' => fake()->boolean(40),
            'notes' => fake()->sentence(),
            'accounting_exported_at' => null,
            'contact_id' => null,
            'user_id' => User::first()?->id,
            'project_id' => null,
            'ticket_id' => null,
            'service_report_id' => null,
            'created_by' => User::first()?->id,
        ];
    }

    /**
     * Mark as approved.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
        ]);
    }

    /**
     * Mark as exported to accountant.
     */
    public function exported(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'accounting_exported_at' => now(),
        ]);
    }
}
