<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Integrations\Models\ApiKey;

/**
 * @extends Factory<ApiKey>
 */
final class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = ApiKey::TokenPrefix.Str::random(48);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Power BI', 'Gestionale magazzino', 'Integrazione e-commerce']),
            'prefix' => substr($secret, 0, 12),
            'key_hash' => hash('sha256', $secret),
            'scopes' => ['reporting.read'],
            'expires_at' => '2027-12-31 23:59:59',
            'last_used_at' => null,
            'revoked_at' => null,
            'created_by' => null,
        ];
    }
}
