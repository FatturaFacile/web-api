<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * @extends Factory<BudgetVersion>
 */
final class BudgetVersionFactory extends Factory
{
    protected $model = BudgetVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Budget '.Str::random(5),
            'fiscal_year' => 2028,
            'year_start' => '2028-01-01',
            'version_type' => BudgetVersion::Budget,
            'status' => BudgetVersion::Draft,
            'currency' => 'EUR',
        ];
    }
}
