<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsSpcCharacteristic;
use Modules\Quality\Models\QmsSpcSample;

/**
 * @extends Factory<QmsSpcSample>
 */
final class QmsSpcSampleFactory extends Factory
{
    protected $model = QmsSpcSample::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'characteristic_id' => QmsSpcCharacteristic::factory(),
            'sampled_at' => now(),
            'measurements' => [10.0, 10.1, 9.9, 10.0, 10.0],
            'mean' => '10.00000',
            'range' => '0.20000',
        ];
    }
}
