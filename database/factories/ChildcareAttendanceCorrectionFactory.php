<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\AttendanceCorrection;

/**
 * @extends Factory<AttendanceCorrection>
 */
final class ChildcareAttendanceCorrectionFactory extends Factory
{
    protected $model = AttendanceCorrection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'attendance_id' => static fn (array $attributes): string => (string) ChildcareAttendanceFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'field' => 'status',
            'old_value' => 'present',
            'new_value' => 'absent',
            'reason' => 'Presenza registrata per errore, la famiglia aveva avvisato dell\'assenza.',
            'corrected_by' => null,
        ];
    }
}
