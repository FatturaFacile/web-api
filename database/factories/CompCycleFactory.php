<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\TalentLearning\Models\CompCycle;

/**
 * @extends Factory<CompCycle>
 */
final class CompCycleFactory extends Factory
{
    protected $model = CompCycle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Merit '.fake()->year(),
            'fiscal_year' => 2027,
            'currency' => 'EUR',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-03-31',
            'effective_on' => '2027-04-01',
            'status' => CompCycle::Draft,
        ];
    }

    public function open(): self
    {
        return $this->state(['status' => CompCycle::Open]);
    }
}
