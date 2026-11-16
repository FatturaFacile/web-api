<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsInstrument;

/**
 * @extends Factory<QmsInstrument>
 */
final class QmsInstrumentFactory extends Factory
{
    protected $model = QmsInstrument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'INS-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'tolerance' => '0.02000',
            'calibration_interval_days' => 365,
            'status' => 'active',
        ];
    }
}
