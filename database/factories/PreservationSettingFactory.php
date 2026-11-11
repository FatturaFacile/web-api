<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DocumentTrust\Models\PreservationSetting;

/**
 * @extends Factory<PreservationSetting>
 */
final class PreservationSettingFactory extends Factory
{
    protected $model = PreservationSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'retention_years' => null, 'retention_confirmed_at' => null, 'retention_confirmed_by' => null, 'notes' => null];
    }
}
