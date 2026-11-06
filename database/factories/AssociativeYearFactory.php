<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\YearKind;
use Modules\Associations\Models\AssociativeYear;

/**
 * @extends Factory<AssociativeYear>
 */
class AssociativeYearFactory extends Factory
{
    protected $model = AssociativeYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'Y'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Current year',
            'kind' => YearKind::Associative,
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ];
    }
}
