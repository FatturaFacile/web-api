<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsAudit;

/**
 * @extends Factory<QmsAudit>
 */
final class QmsAuditFactory extends Factory
{
    protected $model = QmsAudit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'AUD-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(3),
            'audit_type' => 'internal',
            'planned_date' => now()->addDays(14)->toDateString(),
            'status' => 'planned',
        ];
    }
}
