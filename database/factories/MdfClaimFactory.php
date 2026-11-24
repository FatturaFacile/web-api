<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\MdfClaim;
use Modules\CRM\Models\MdfFund;

/**
 * @extends Factory<MdfClaim>
 */
final class MdfClaimFactory extends Factory
{
    protected $model = MdfClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'fund_id' => MdfFund::factory(),
            'activity' => fake()->sentence(3),
            'amount' => '1000.00',
            'status' => 'submitted',
        ];
    }
}
