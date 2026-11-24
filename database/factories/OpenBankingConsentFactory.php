<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\OpenBankingConsent;

/**
 * @extends Factory<OpenBankingConsent>
 */
final class OpenBankingConsentFactory extends Factory
{
    protected $model = OpenBankingConsent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'driver' => 'local',
            'iban' => 'CH9300762011623852957',
            'external_consent_id' => 'LOCAL-'.(string) Str::uuid7(),
            'access_token' => Str::random(40),
            'status' => 'active',
            'expires_at' => now()->addDays(90),
        ];
    }
}
