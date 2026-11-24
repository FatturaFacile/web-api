<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\JobPosting;

/**
 * @extends Factory<JobPosting>
 */
final class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->randomElement(['Contabile senior', 'Impiegato amministrativo', 'Sviluppatore PHP', 'Magazziniere', 'Responsabile paghe']),
            'description' => 'Cerchiamo una persona motivata da inserire nel nostro team. Sede di lavoro e condizioni secondo CCL.',
            'department_id' => static fn (array $attributes): string => (string) Department::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'position_id' => null,
            'location' => fake()->randomElement(['Lugano', 'Mendrisio', 'Bellinzona', 'Milano', 'Como']),
            'employment_type' => fake()->randomElement(['full_time', 'part_time']),
            'status' => JobPosting::StatusOpen,
            'opens_on' => '2026-11-01',
            'closes_on' => '2026-12-31',
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => JobPosting::StatusDraft, 'opens_on' => null, 'closes_on' => null]);
    }
}
