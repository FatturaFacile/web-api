<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractRenewal;

/**
 * @extends Factory<ContractRenewal>
 */
final class ContractRenewalFactory extends Factory
{
    protected $model = ContractRenewal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contract_kind' => 'customer',
            'contract_id' => (string) Str::uuid7(),
            'previous_end_date' => now()->toDateString(),
            'new_end_date' => now()->addYear()->toDateString(),
            'mode' => 'manual',
        ];
    }
}
