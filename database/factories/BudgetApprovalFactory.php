<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetApproval;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * @extends Factory<BudgetApproval>
 */
final class BudgetApprovalFactory extends Factory
{
    protected $model = BudgetApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'version_id' => BudgetVersion::factory(),
            'action' => BudgetApproval::Submit,
            'from_status' => BudgetVersion::Draft,
            'to_status' => BudgetVersion::Submitted,
        ];
    }
}
