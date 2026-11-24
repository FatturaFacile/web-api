<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\DepreciationRun;

/**
 * @extends Factory<DepreciationRun>
 */
class DepreciationRunFactory extends Factory
{
    protected $model = DepreciationRun::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'run_number' => 'DEPR-RUN-'.strtoupper(Str::random(6)),
            'period_code' => date('Y-m'),
            'period_type' => 'monthly',
            'ledger_type' => 'statutory',
            'assets_processed_count' => 5,
            'total_depreciation_amount' => 2500.00,
            'status' => 'posted',
            'executed_at' => now(),
            'posted_at' => now(),
        ];
    }
}
