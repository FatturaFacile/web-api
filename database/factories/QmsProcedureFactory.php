<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsProcedure;

/**
 * @extends Factory<QmsProcedure>
 */
final class QmsProcedureFactory extends Factory
{
    protected $model = QmsProcedure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'PRO-'.fake()->unique()->numerify('###'),
            'version' => 1,
            'title' => fake()->sentence(3),
            'status' => 'draft',
        ];
    }
}
