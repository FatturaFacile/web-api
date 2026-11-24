<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\PrivacySubject;

/**
 * @extends Factory<PrivacySubject>
 */
final class PrivacySubjectFactory extends Factory
{
    protected $model = PrivacySubject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'role' => 'dpo',
            'name' => fake()->name(),
            'representative' => null,
            'tax_code' => strtoupper(fake()->bothify('??????##?##?###?')),
            'email' => fake()->safeEmail(),
            'address' => fake()->streetAddress().', '.fake()->postcode().' '.fake()->city(),
            'scope' => 'Responsabile della protezione dei dati dell\'associazione',
            'appointed_on' => '2026-01-10',
            'ended_on' => null,
        ];
    }
}
