<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceAppointment;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

class ServiceAppointmentFactory extends Factory
{
    protected $model = ServiceAppointment::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'appointment_number' => 'APT-'.date('Ymd').'-'.strtoupper(Str::random(5)),
            'work_order_id' => WorkOrder::factory(),
            'technician_id' => ServiceTechnician::factory(),
            'customer_id' => (string) Str::uuid7(),
            'start_time' => now()->addDay()->setHour(9)->setMinute(0),
            'end_time' => now()->addDay()->setHour(12)->setMinute(0),
            'status' => 'scheduled',
            'notes' => fake()->sentence(),
        ];
    }
}
