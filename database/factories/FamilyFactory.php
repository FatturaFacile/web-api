<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Family;

/**
 * @extends Factory<Family>
 */
final class FamilyFactory extends Factory
{
    protected $model = Family::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Famiglia '.fake()->lastName(),
        ];
    }
}
