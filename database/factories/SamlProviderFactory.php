<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SamlProvider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SamlProvider>
 */
final class SamlProviderFactory extends Factory
{
    protected $model = SamlProvider::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Corporate SAML IdP',
            'idp_entity_id' => 'https://saml.example.com/entity',
            'idp_sso_url' => 'https://saml.example.com/sso',
            'idp_slo_url' => 'https://saml.example.com/slo',
            'idp_certificates' => [],
            'allowed_email_domains' => ['example.com'],
            'default_role' => 'member',
            'jit_provisioning' => false,
            'enabled' => true,
        ];
    }
}
