<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateTier;

/**
 * @extends Factory<RebateTier>
 */
final class RebateTierFactory extends Factory
{
    protected $model = RebateTier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'agreement_id' => RebateAgreement::factory(),
            'threshold_amount' => '10000.00',
            'rate_percent' => '2.0000',
        ];
    }
}
