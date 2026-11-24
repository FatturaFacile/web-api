<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractAlert;

/**
 * @extends Factory<ContractAlert>
 */
final class ContractAlertFactory extends Factory
{
    protected $model = ContractAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contract_kind' => 'customer',
            'contract_id' => (string) Str::uuid7(),
            'alert_type' => 'expiry',
            'due_on' => now()->addDays(10)->toDateString(),
            'status' => 'open',
        ];
    }
}
