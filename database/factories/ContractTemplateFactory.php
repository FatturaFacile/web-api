<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractTemplate;

/**
 * @extends Factory<ContractTemplate>
 */
final class ContractTemplateFactory extends Factory
{
    protected $model = ContractTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Service agreement',
            'contract_kind' => 'customer',
            'status' => 'active',
            'variables' => [],
        ];
    }
}
