<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsAudit;
use Modules\Quality\Models\QmsAuditFinding;

/**
 * @extends Factory<QmsAuditFinding>
 */
final class QmsAuditFindingFactory extends Factory
{
    protected $model = QmsAuditFinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'audit_id' => QmsAudit::factory(),
            'number' => 'FND-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'classification' => 'observation',
            'description' => fake()->sentence(),
            'status' => 'open',
        ];
    }
}
