<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsCalibration;
use Modules\Quality\Models\QmsInstrument;

/**
 * @extends Factory<QmsCalibration>
 */
final class QmsCalibrationFactory extends Factory
{
    protected $model = QmsCalibration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'instrument_id' => QmsInstrument::factory(),
            'calibrated_on' => now()->toDateString(),
            'next_due_on' => now()->addYear()->toDateString(),
            'result' => 'pass',
        ];
    }
}
