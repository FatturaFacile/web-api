<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Enums\ApplicationStatus;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;

/**
 * @extends Factory<EnrolmentApplication>
 */
final class EnrolmentApplicationFactory extends Factory
{
    protected $model = EnrolmentApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'form_id' => static fn (array $attributes): string => EnrolmentForm::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'member_id' => null,
            'membership_id' => null,
            'status' => ApplicationStatus::Submitted,
            'payload' => [
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'email' => fake()->safeEmail(),
                'phone' => fake()->numerify('+39 3## #######'),
                'birth_date' => '1990-05-14',
                'tax_code' => strtoupper(fake()->bothify('??????##?##?###?')),
            ],
            'tracking_hash' => hash('sha256', Str::random(40)),
            'submitted_ip' => fake()->ipv4(),
        ];
    }
}
