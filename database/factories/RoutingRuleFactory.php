<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\RoutingRule;

/**
 * @extends Factory<RoutingRule>
 */
class RoutingRuleFactory extends Factory
{
    protected $model = RoutingRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Rule '.Str::random(4),
            'position' => 100,
            'is_active' => true,
            'conditions' => [],
            'actions' => ['add_tags' => ['routed']],
            'stop_processing' => false,
        ];
    }
}
