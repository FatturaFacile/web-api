<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopJobSlot;

/**
 * @extends Factory<WorkshopJobSlot>
 */
final class WorkshopJobSlotFactory extends Factory
{
    protected $model = WorkshopJobSlot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'folder_id' => static fn (array $attributes): string => (string) WorkshopFolder::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'starts_at' => now()->addDay()->setTime(8, 0),
            'ends_at' => now()->addDay()->setTime(10, 0),
            'status' => 'planned',
        ];
    }
}
