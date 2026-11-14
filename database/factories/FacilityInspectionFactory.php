<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Facility\Models\FacilityInspection;

/**
 * @extends Factory<FacilityInspection>
 */
final class FacilityInspectionFactory extends Factory
{
    protected $model = FacilityInspection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'number' => 'INS-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'title' => fake()->words(3, true),
            'due_date' => now()->toDateString(),
            'status' => 'scheduled',
            'checklist' => [
                ['key' => 'visual', 'label' => 'Visual check', 'type' => 'pass_fail', 'required' => true, 'result' => null, 'value' => null, 'notes' => null],
            ],
        ];
    }
}
