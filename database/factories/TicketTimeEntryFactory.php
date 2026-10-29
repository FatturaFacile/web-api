<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketTimeEntry;

class TicketTimeEntryFactory extends Factory
{
    protected $model = TicketTimeEntry::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'ticket_id' => Ticket::factory(),
            'technician_id' => ServiceTechnician::factory(),
            'hours' => 2.0,
            'hourly_rate' => 75.00,
            'hourly_cost' => 35.00,
            'total_cost' => 70.00,
            'total_charge' => 150.00,
            'is_billable' => true,
            'work_date' => now()->toDateString(),
            'description' => fake()->sentence(),
        ];
    }
}
