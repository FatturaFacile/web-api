<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\PortalAccessToken;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalAccessToken>
 */
class PortalAccessTokenFactory extends Factory
{
    protected $model = PortalAccessToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $contact = Contact::where('tenant_id', $tenantId)->first() ?? Contact::factory()->create(['tenant_id' => $tenantId]);

        return [
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'token_type' => 'dashboard',
            'expires_at' => now()->addDays(30),
            'is_single_use' => false,
        ];
    }
}
