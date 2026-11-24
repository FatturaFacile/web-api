<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditInsurancePolicy;

/**
 * @extends Factory<CreditInsurancePolicy>
 */
final class CreditInsurancePolicyFactory extends Factory
{
    protected $model = CreditInsurancePolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'insurer' => 'Insurer '.Str::random(4),
            'policy_number' => 'POL-'.Str::upper(Str::random(8)),
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'currency' => 'EUR',
            'coverage_percentage' => '90.00',
            'max_total_cover' => '500000.00',
            'status' => CreditInsurancePolicy::Active,
        ];
    }
}
