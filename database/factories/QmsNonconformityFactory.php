<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsNonconformity;

/**
 * @extends Factory<QmsNonconformity>
 */
final class QmsNonconformityFactory extends Factory
{
    protected $model = QmsNonconformity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'NC-'.fake()->year().'-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'source' => fake()->randomElement(QmsNonconformity::Sources),
            'severity' => 'minor',
            'status' => 'open',
            'detected_at' => now()->toDateString(),
        ];
    }
}
