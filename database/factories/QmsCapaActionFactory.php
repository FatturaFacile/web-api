<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsCapaAction;

/**
 * @extends Factory<QmsCapaAction>
 */
final class QmsCapaActionFactory extends Factory
{
    protected $model = QmsCapaAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'CAPA-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'type' => 'corrective',
            'title' => fake()->sentence(4),
            'owner_id' => (string) Str::uuid7(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'open',
        ];
    }
}
