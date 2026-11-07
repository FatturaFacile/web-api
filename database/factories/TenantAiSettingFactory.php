<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Documents\Models\TenantAiSetting;

/**
 * @extends Factory<TenantAiSetting>
 */
final class TenantAiSettingFactory extends Factory
{
    protected $model = TenantAiSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'external_providers_enabled' => false,
        ];
    }
}
