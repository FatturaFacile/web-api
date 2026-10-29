<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\WorkOrder;

class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'work_order_number' => 'WO-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'work_type' => fake()->randomElement(['corrective', 'preventive', 'inspection', 'installation']),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => 'draft',
            'customer_id' => (string) Str::uuid7(),
            'labor_cost' => 175.00,
            'material_cost' => 50.00,
            'total_cost' => 225.00,
            'total_billable_amount' => 350.00,
            'is_invoiced' => false,
            'customer_signed' => false,
            'is_locked' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => 'scheduled',
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHours(3),
        ]);
    }

    public function dispatched(): static
    {
        return $this->state(fn () => [
            'status' => 'dispatched',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'actual_start' => now()->subHour(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'actual_start' => now()->subHours(3),
            'actual_end' => now(),
            'resolution_notes' => 'Service and repairs successfully completed on-site',
            'signed_by_name' => 'Mario Rossi',
            'customer_signed' => true,
            'signed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
