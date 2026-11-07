<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\Department;
use Modules\Payroll\Models\ShortTimeCase;

/**
 * @extends Factory<ShortTimeCase>
 */
final class ShortTimeCaseFactory extends Factory
{
    protected $model = ShortTimeCase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => ShortTimeCase::ShortTime,
            'department_id' => static fn (array $attributes): string => (string) Department::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'cause' => 'Calo degli ordini dal mercato tedesco a seguito della congiuntura sfavorevole.',
            'notified_on' => '2026-10-15',
            'is_urgent' => false,
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-01-31',
            'authority_reference' => 'UFFICIO-LAVORO-TI-'.fake()->numerify('2026-####'),
            'status' => 'notified',
        ];
    }

    public function badWeather(): static
    {
        return $this->state(['kind' => ShortTimeCase::BadWeather, 'cause' => 'Neve e gelo impediscono i lavori di cantiere.']);
    }
}
