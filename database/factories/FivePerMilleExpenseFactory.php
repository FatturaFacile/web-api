<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\ExpenseCategory;
use Modules\Associations\Models\FivePerMilleAllocation;
use Modules\Associations\Models\FivePerMilleExpense;

/**
 * @extends Factory<FivePerMilleExpense>
 */
final class FivePerMilleExpenseFactory extends Factory
{
    protected $model = FivePerMilleExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'allocation_id' => static fn (array $attributes): string => FivePerMilleAllocation::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'category_id' => static fn (array $attributes): string => ExpenseCategory::factory()->create(['tenant_id' => $attributes['tenant_id'], 'scope' => ExpenseCategory::FivePerMille])->id,
            'incurred_on' => '2026-11-20',
            'description' => 'Acquisto attrezzature per il centro estivo',
            'amount' => number_format(fake()->randomFloat(2, 50, 400), 2, '.', ''),
        ];
    }
}
