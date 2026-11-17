<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;

/**
 * @extends Factory<JobOpening>
 */
class JobOpeningFactory extends Factory
{
    protected $model = JobOpening::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'reference' => 'JOB-'.now()->format('Y').'-'.strtoupper(Str::random(6)),
            'position_id' => function (array $attrs) {
                return Position::factory()->create(['tenant_id' => $attrs['tenant_id'], 'headcount_budget' => 5])->id;
            },
            'department_id' => function (array $attrs) {
                return Position::withoutGlobalScopes()->find($attrs['position_id'])?->department_id;
            },
            'location_site' => 'Lugano',
            'employment_type' => 'full_time',
            'openings_count' => 1,
            'hires_count' => 0,
            'salary_min' => 70000,
            'salary_max' => 90000,
            'salary_currency' => 'CHF',
            'status' => 'draft',
            'published_languages' => ['it-CH', 'en'],
            'translations' => [
                'it-CH' => [
                    'title' => 'Sviluppatore PHP',
                    'description' => 'Sviluppo della piattaforma ERP.',
                    'requirements' => 'Laravel, PostgreSQL',
                    'benefits' => 'Lavoro ibrido',
                ],
                'en' => [
                    'title' => 'PHP Developer',
                    'description' => 'Development of the ERP platform.',
                    'requirements' => 'Laravel, PostgreSQL',
                    'benefits' => 'Hybrid work',
                ],
            ],
            'closes_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()->subDay()]);
    }

    public function internalOnly(): static
    {
        return $this->state(fn (): array => ['status' => 'internal_only', 'published_at' => now()->subDay()]);
    }
}
