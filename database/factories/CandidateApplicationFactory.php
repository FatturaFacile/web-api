<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOpening;

/**
 * @extends Factory<CandidateApplication>
 */
class CandidateApplicationFactory extends Factory
{
    protected $model = CandidateApplication::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'reference' => 'APP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'job_opening_id' => function (array $attrs) {
                return JobOpening::factory()->published()->create(['tenant_id' => $attrs['tenant_id']])->id;
            },
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower(Str::random(6)).'.'.fake()->unique()->safeEmail(),
            'phone' => '+41 91 123 45 67',
            'locale' => 'en',
            'source' => 'careers_page',
            'stage' => 'applied',
            'stage_entered_at' => now(),
            'board_position' => 0,
            'cv_parse_status' => 'not_provided',
            'skills' => [],
            'consent_given_at' => now(),
            'consent_text_version' => 'v1',
            'retention_months' => 6,
            'privacy_consent_expires_at' => now()->addMonths(6),
        ];
    }

    public function inStage(string $stage): static
    {
        return $this->state(fn (): array => ['stage' => $stage, 'stage_entered_at' => now()]);
    }

    public function consentExpired(): static
    {
        return $this->state(fn (): array => [
            'consent_given_at' => now()->subMonths(7),
            'privacy_consent_expires_at' => now()->subMonth(),
        ]);
    }
}
