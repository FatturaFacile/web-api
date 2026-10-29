<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;

class BusinessHoursCalendarFactory extends Factory
{
    protected $model = BusinessHoursCalendar::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CAL-'.strtoupper(Str::random(6)),
            'name' => 'Standard Business Hours',
            'timezone' => 'Europe/Rome',
            'weekly_schedule' => [
                'monday' => ['start' => '09:00', 'end' => '18:00'],
                'tuesday' => ['start' => '09:00', 'end' => '18:00'],
                'wednesday' => ['start' => '09:00', 'end' => '18:00'],
                'thursday' => ['start' => '09:00', 'end' => '18:00'],
                'friday' => ['start' => '09:00', 'end' => '18:00'],
            ],
            'is_active' => true,
        ];
    }
}
