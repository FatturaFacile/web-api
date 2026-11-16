<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAgreement;

/**
 * @extends Factory<RebateAccrual>
 */
final class RebateAccrualFactory extends Factory
{
    protected $model = RebateAccrual::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'agreement_id' => RebateAgreement::factory(),
            'as_of' => '2027-03-31',
            'base_amount' => '10000.00',
            'cumulative_rebate' => '200.00',
            'rebate_amount' => '200.00',
            'applied_rate_percent' => '2.0000',
            'status' => RebateAccrual::Accrued,
        ];
    }
}
