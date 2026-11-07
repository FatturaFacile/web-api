<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\PipelineStage;

/**
 * @extends Factory<JobApplication>
 */
final class JobApplicationFactory extends Factory
{
    protected $model = JobApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'tenant_id' => Tenant::factory(),
            'job_posting_id' => static fn (array $attributes): string => (string) JobPosting::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'pipeline_stage_id' => static fn (array $attributes): string => (string) PipelineStage::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower($firstName.'.'.$lastName).'.'.fake()->unique()->numerify('####').'@example.ch',
            'phone' => '+41 91 '.fake()->numerify('### ## ##'),
            'cover_note' => 'Gentili signori, vi invio la mia candidatura per la posizione pubblicata.',
            'source' => fake()->randomElement(['sito web', 'jobs.ch', 'LinkedIn', 'passaparola']),
            'status' => JobApplication::StatusActive,
            'consent_until' => '2027-05-31',
            'retention_until' => '2027-05-31',
            'decided_at' => null,
            'employee_id' => null,
        ];
    }
}
