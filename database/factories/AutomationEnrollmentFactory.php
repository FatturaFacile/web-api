<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\AutomationEnrollment;
use Modules\CRM\Models\AutomationSequence;

/**
 * @extends Factory<AutomationEnrollment>
 */
final class AutomationEnrollmentFactory extends Factory
{
    protected $model = AutomationEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'sequence_id' => AutomationSequence::factory(),
            'status' => 'active',
            'current_position' => 0,
            'next_run_at' => now(),
            'enrolled_at' => now(),
        ];
    }
}
