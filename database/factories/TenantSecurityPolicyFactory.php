<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSecurityPolicy>
 */
final class TenantSecurityPolicyFactory extends Factory
{
    protected $model = TenantSecurityPolicy::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'password_min_length' => 12,
            'password_history_count' => 3,
            'lockout_max_attempts' => 5,
            'lockout_minutes' => 15,
        ];
    }
}
