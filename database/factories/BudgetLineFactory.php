<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * @extends Factory<BudgetLine>
 */
final class BudgetLineFactory extends Factory
{
    protected $model = BudgetLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'version_id' => BudgetVersion::factory(),
            'account_id' => (string) Str::uuid7(),
            'period' => 1,
            'amount' => '1000.00',
            'source' => BudgetLine::Manual,
        ];
    }
}
