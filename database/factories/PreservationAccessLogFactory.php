<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\DocumentTrust\Models\PreservationAccessLog;

/**
 * @extends Factory<PreservationAccessLog>
 */
final class PreservationAccessLogFactory extends Factory
{
    protected $model = PreservationAccessLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'package_id' => null, 'user_id' => null, 'action' => 'view', 'ip_address' => '127.0.0.1', 'details' => [], 'created_at' => now()];
    }
}
