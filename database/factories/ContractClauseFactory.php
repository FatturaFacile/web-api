<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractClause;

/**
 * @extends Factory<ContractClause>
 */
final class ContractClauseFactory extends Factory
{
    protected $model = ContractClause::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CL-'.strtoupper(Str::random(6)),
            'title' => 'Confidentiality',
            'category' => 'legal',
            'status' => 'draft',
            'current_version_no' => 0,
        ];
    }
}
