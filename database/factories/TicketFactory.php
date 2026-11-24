<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\Ticket;

class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'ticket_number' => 'TCK-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'customer_id' => (string) Str::uuid7(),
            'type' => 'incident',
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => 'open',
            'source' => fake()->randomElement(['portal', 'email', 'phone', 'chat']),
            'response_due_at' => now()->addHours(4),
            'resolution_due_at' => now()->addHours(24),
            'is_escalated' => false,
            'escalation_level' => 0,
            'is_locked' => false,
            'metadata' => ['is_sla_breached' => false],
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function assigned(): static
    {
        return $this->state(fn () => [
            'status' => 'assigned',
            'assigned_technician_id' => (string) Str::uuid7(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'assigned_technician_id' => (string) Str::uuid7(),
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn () => [
            'status' => 'escalated',
            'is_escalated' => true,
            'escalation_level' => 1,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolution_summary' => 'Resolved following standard SOP',
            'root_cause' => 'Configuration defect',
            'resolved_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'resolved_at' => now()->subHours(2),
            'closed_at' => now(),
        ]);
    }

    public function breached(): static
    {
        return $this->state(fn () => [
            'status' => 'open',
            'resolution_due_at' => now()->subHour(),
            'metadata' => ['is_sla_breached' => true],
        ]);
    }
}
