<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\ServiceVisit;
use Modules\ServiceManagement\Models\WorkOrder;

class ServiceVisitFactory extends Factory
{
    protected $model = ServiceVisit::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'work_order_id' => WorkOrder::factory(),
            'technician_id' => ServiceTechnician::factory(),
            'is_successful' => true,
            'check_in_at' => now()->subHours(2),
            'check_out_at' => now(),
            'duration_minutes' => 120,
            'visit_summary' => fake()->sentence(),
            'metadata' => [
                'visit_number' => 'VIS-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'status' => 'completed',
            ],
        ];
    }
}
