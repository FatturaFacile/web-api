<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOffer;

/**
 * @extends Factory<JobOffer>
 */
class JobOfferFactory extends Factory
{
    protected $model = JobOffer::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'application_id' => function (array $attrs) {
                return CandidateApplication::factory()->create(['tenant_id' => $attrs['tenant_id'], 'stage' => 'offer_extended'])->id;
            },
            'proposed_salary' => 85000,
            'currency' => 'CHF',
            'start_date' => now()->addMonth()->toDateString(),
            'employment_type' => 'full_time',
            'contract_type' => 'permanent',
            'weekly_hours' => 40,
            'probation_months' => 3,
            'valid_until' => now()->addWeeks(2)->toDateString(),
            'status' => 'sent',
            'signature_status' => 'pending',
            'signature_provider' => 'manual',
            'signature_external_id' => 'MAN-'.strtoupper(Str::random(12)),
            'rendered_body' => '<p>Offer</p>',
            'locale' => 'en',
            'sent_at' => now(),
        ];
    }

    public function signed(): static
    {
        return $this->state(fn (): array => ['status' => 'signed', 'signature_status' => 'signed', 'signed_at' => now()]);
    }
}
