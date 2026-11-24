<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\SubsidyRule;

/**
 * @extends Factory<SubsidyRule>
 */
final class ChildcareSubsidyRuleFactory extends Factory
{
    protected $model = SubsidyRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'canton' => 'TI',
            'name' => 'Sussidio cantonale per la custodia di bambini',
            'method' => 'fixed_per_day',
            'parameters' => ['amount_per_day' => '20.00', 'monthly_cap' => '400.00'],
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_reference' => null,
            'verified_at' => null,
            'verified_by' => null,
        ];
    }
}
