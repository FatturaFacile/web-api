<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Governance\Models\GovAudit;

/**
 * @extends Factory<GovAudit>
 */
final class GovAuditFactory extends Factory
{
    protected $model = GovAudit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'AUD-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'audit_type' => fake()->randomElement(GovAudit::Types),
            'scope' => fake()->sentence(),
            'lead_auditor_id' => (string) Str::uuid7(),
            'planned_start' => '2027-09-01',
            'planned_end' => '2027-09-15',
            'status' => 'planned',
        ];
    }
}
