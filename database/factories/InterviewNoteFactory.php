<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\InterviewNote;
use Modules\HumanResources\Models\JobApplication;

/**
 * @extends Factory<InterviewNote>
 */
final class InterviewNoteFactory extends Factory
{
    protected $model = InterviewNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'job_application_id' => static fn (array $attributes): string => (string) JobApplication::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'interviewer_id' => null,
            'interviewed_at' => fake()->dateTimeBetween('2026-11-02', '2026-11-30'),
            'rating' => fake()->numberBetween(1, 5),
            'note' => 'Buona preparazione tecnica, comunicazione chiara. Disponibile da gennaio 2027.',
        ];
    }
}
