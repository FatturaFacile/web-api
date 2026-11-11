<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Customs\Models\CustomsPackagingType;

/**
 * @extends Factory<CustomsPackagingType>
 */
class CustomsPackagingTypeFactory extends Factory
{
    protected $model = CustomsPackagingType::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name_it' => fake()->word(),
        ];
    }

    /**
     * Shared by every company and read-only for them.
     */
    public function generic(): self
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
