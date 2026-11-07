<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\NetworkLink;

/**
 * @extends Factory<NetworkLink>
 */
final class NetworkLinkFactory extends Factory
{
    protected $model = NetworkLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'parent_tenant_id' => Tenant::factory(),
            'status' => NetworkLink::Pending,
            'requested_at' => '2026-10-01 09:30:00',
            'consent_granted_at' => null,
            'consent_withdrawn_at' => null,
        ];
    }
}
