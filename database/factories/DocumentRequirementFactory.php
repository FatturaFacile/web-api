<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\DocumentRequirement;

/**
 * @extends Factory<DocumentRequirement>
 */
final class DocumentRequirementFactory extends Factory
{
    protected $model = DocumentRequirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'DOC_'.strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(3, true),
            'country' => null,
            'is_mandatory' => true,
            'requires_expiry' => true,
            'validity_days' => null,
            'alert_days' => 30,
            'is_active' => true,
            'sort_order' => 10,
        ];
    }
}
