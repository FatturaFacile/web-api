<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Associations\Enums\CredentialKind;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<Credential>
 */
final class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'kind' => CredentialKind::Qr,
            'public_id' => Str::random(32),
            'lookup_hash' => null,
            'expires_at' => '2027-08-31 23:59:59',
        ];
    }

    public function badge(): static
    {
        return $this->state(['kind' => CredentialKind::Badge, 'public_id' => null, 'lookup_hash' => hash('sha256', Str::random(16))]);
    }
}
