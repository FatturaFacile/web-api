<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\AutomationEnrollment;
use Modules\CRM\Models\AutomationStepLog;

/**
 * @extends Factory<AutomationStepLog>
 */
final class AutomationStepLogFactory extends Factory
{
    protected $model = AutomationStepLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'enrollment_id' => AutomationEnrollment::factory(),
            'position' => 1,
            'step_type' => 'email',
            'status' => 'sent',
            'executed_at' => now(),
        ];
    }
}
