<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\DmsFolder;

/**
 * @extends Factory<DmsFolder>
 */
final class DmsFolderFactory extends Factory
{
    protected $model = DmsFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'access_roles' => [],
        ];
    }
}
