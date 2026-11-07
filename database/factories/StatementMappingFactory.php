<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\StatementMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;

/**
 * @extends Factory<StatementMapping>
 */
final class StatementMappingFactory extends Factory
{
    protected $model = StatementMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'scheme' => 'D',
            'line_code' => 'A1',
            'account_id' => static fn (array $attributes): string => (string) ChartOfAccount::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
            ])->id,
            'cost_center_id' => null,
            'is_commercial' => false,
        ];
    }
}
