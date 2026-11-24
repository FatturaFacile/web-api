<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Models\WorkspaceSyncedItem;

/**
 * @extends Factory<WorkspaceSyncedItem>
 */
final class WorkspaceSyncedItemFactory extends Factory
{
    protected $model = WorkspaceSyncedItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => WorkspaceConnection::factory(),
            'tenant_id' => fn (array $attributes): string => (string) WorkspaceConnection::withoutGlobalScopes()->findOrFail($attributes['connection_id'])->tenant_id,
            'kind' => 'calendar_event',
            'external_id' => $this->faker->uuid(),
            'subject' => 'Meeting',
            'starts_at' => now(),
        ];
    }
}
