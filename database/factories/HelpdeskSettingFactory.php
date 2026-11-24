<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\HelpdeskSetting;

/**
 * @extends Factory<HelpdeskSetting>
 */
class HelpdeskSettingFactory extends Factory
{
    protected $model = HelpdeskSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'reopen_window_days' => 7,
            'sla_warning_percent' => 80,
            'csat_enabled' => true,
        ];
    }
}
