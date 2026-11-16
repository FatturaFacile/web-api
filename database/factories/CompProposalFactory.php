<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\CompBudgetPool;
use Modules\TalentLearning\Models\CompCycle;
use Modules\TalentLearning\Models\CompProposal;

/**
 * @extends Factory<CompProposal>
 */
final class CompProposalFactory extends Factory
{
    protected $model = CompProposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'cycle_id' => CompCycle::factory(),
            'budget_pool_id' => CompBudgetPool::factory(),
            'employee_id' => (string) Str::uuid7(),
            'performance_rating' => 3,
            'current_salary' => '40000.00',
            'increase_percent' => '3.00',
            'increase_amount' => '1200.00',
            'new_salary' => '41200.00',
            'is_exception' => false,
            'status' => CompProposal::Draft,
        ];
    }
}
