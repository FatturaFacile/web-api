<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\Warehouse;

/**
 * @extends Factory<InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
{
    protected $model = InventoryAdjustment::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'warehouse_id' => Warehouse::factory(),
            'adjustment_number' => 'ADJ-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('?###')),
            'reason' => 'cycle_count_variance',
            'status' => 'draft',
            'net_valuation_change' => 0.0,
            'is_locked' => false,
            'created_by' => (string) Str::uuid(),
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn () => [
            'status' => 'pending_approval',
            'net_valuation_change' => -750.00,
            'is_locked' => true,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'is_locked' => false,
            'approved_at' => now(),
            'approved_by' => (string) Str::uuid(),
        ]);
    }
}
