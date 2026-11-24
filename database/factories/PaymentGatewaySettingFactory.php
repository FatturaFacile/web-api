<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Integrations\Models\PaymentGatewaySetting;

/**
 * @extends Factory<PaymentGatewaySetting>
 */
final class PaymentGatewaySettingFactory extends Factory
{
    protected $model = PaymentGatewaySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => 'local',
            'credentials' => ['webhook_secret' => Str::random(32)],
        ];
    }
}
