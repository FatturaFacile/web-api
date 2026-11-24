<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\OpportunityStage;

/**
 * @extends Factory<OpportunityStage>
 */
final class OpportunityStageFactory extends Factory
{
    protected $model = OpportunityStage::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'discovery',
            'name' => 'Discovery',
            'sequence' => 10,
            'default_probability' => 20.00,
            'is_won' => false,
            'is_lost' => false,
            'stale_days' => 30,
            'is_active' => true,
        ];
    }
}
