<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<InventoryCountSession>
 */
class InventoryCountSessionFactory extends Factory
{
    protected $model = InventoryCountSession::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'warehouse_id' => Warehouse::factory(),
            'session_number' => 'CNT-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('?###')),
            'count_type' => 'cycle_count_abc',
            'status' => 'draft',
            'scheduled_date' => now()->toDateString(),
            'is_locked' => false,
            'created_by' => (string) Str::uuid(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => 'in_progress']);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
