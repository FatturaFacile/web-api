<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Hospitality\Models\HousekeepingTask;

/**
 * @extends Factory<HousekeepingTask>
 */
final class HospitalityHousekeepingTaskFactory extends Factory
{
    protected $model = HousekeepingTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'accommodation_id' => HospitalityAccommodationFactory::new(),
            'task_type' => 'checkout_clean',
            'status' => 'pending',
            'due_on' => now()->toDateString(),
        ];
    }
}
