<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Models\GovFinding;

/**
 * @extends Factory<GovAction>
 */
final class GovActionFactory extends Factory
{
    protected $model = GovAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'finding_id' => GovFinding::factory(),
            'code' => 'ACT-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'owner_id' => (string) Str::uuid7(),
            'due_date' => fake()->dateTimeBetween('+1 week', '+2 months')->format('Y-m-d'),
            'status' => 'open',
        ];
    }
}
