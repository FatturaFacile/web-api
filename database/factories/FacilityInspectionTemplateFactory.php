<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityInspectionTemplate;

/**
 * @extends Factory<FacilityInspectionTemplate>
 */
final class FacilityInspectionTemplateFactory extends Factory
{
    protected $model = FacilityInspectionTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Estintori', 'Luci di emergenza', 'Impianto HVAC']),
            'frequency' => 'monthly',
            'interval_count' => 1,
            'checklist' => [
                ['key' => 'visual', 'label' => 'Visual check', 'type' => 'pass_fail', 'required' => true],
                ['key' => 'pressure', 'label' => 'Pressure (bar)', 'type' => 'number', 'required' => false],
            ],
            'next_due_date' => now()->toDateString(),
            'lead_days' => 0,
            'is_active' => true,
        ];
    }
}
