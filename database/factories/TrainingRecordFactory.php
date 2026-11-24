<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\TrainingCourse;
use Modules\HumanResources\Models\TrainingRecord;

/**
 * @extends Factory<TrainingRecord>
 */
class TrainingRecordFactory extends Factory
{
    protected $model = TrainingRecord::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'employee_id' => function (array $attrs) {
                return Employee::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'course_id' => function (array $attrs) {
                return TrainingCourse::factory()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'enrollment_number' => 'TRN-'.strtoupper(Str::random(8)),
            'status' => 'assigned',
            'assigned_date' => now()->toDateString(),
            'scheduled_date' => now()->addDays(14)->toDateString(),
            'completed_date' => null,
            'score' => null,
            'cost_amount' => 650.00,
            'currency' => 'EUR',
            'is_locked' => false,
            'certificate_issued' => false,
        ];
    }
}
