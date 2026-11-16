<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\CompBudgetPool;
use Modules\TalentLearning\Models\CompCycle;

/**
 * @extends Factory<CompBudgetPool>
 */
final class CompBudgetPoolFactory extends Factory
{
    protected $model = CompBudgetPool::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'cycle_id' => CompCycle::factory(),
            'name' => 'Pool '.Str::random(5),
            'budget_amount' => '10000.00',
        ];
    }
}
