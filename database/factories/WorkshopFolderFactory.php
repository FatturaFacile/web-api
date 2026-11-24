<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Workshop\Models\WorkshopFolder;

/**
 * @extends Factory<WorkshopFolder>
 */
class WorkshopFolderFactory extends Factory
{
    protected $model = WorkshopFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'vehicle_id' => static fn (array $attributes): string => (string) Vehicle::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'contact_id' => static fn (array $attributes): string => (string) Vehicle::withoutGlobalScopes()->findOrFail($attributes['vehicle_id'])->client_id,
            'folder_number' => strtoupper(fake()->unique()->bothify('WF-########')),
            'kind' => 'mechanics',
            'status' => 'open',
            'currency' => 'CHF',
        ];
    }
}
