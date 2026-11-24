<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SsoProvider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SsoProvider>
 */
final class SsoProviderFactory extends Factory
{
    protected $model = SsoProvider::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Corporate IdP',
            'issuer' => 'https://idp.example.com',
            'authorization_endpoint' => 'https://idp.example.com/authorize',
            'token_endpoint' => 'https://idp.example.com/token',
            'jwks_uri' => 'https://idp.example.com/jwks',
            'redirect_uri' => 'https://app.example.com/auth/sso/callback',
            'client_id' => 'client-'.Str::random(8),
            'client_secret' => Str::random(32),
            'scopes' => 'openid email profile',
            'allowed_email_domains' => ['example.com'],
            'default_role' => 'member',
            'jit_provisioning' => false,
            'enabled' => true,
        ];
    }
}
