<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\AccessReason;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\Member;

/**
 * @extends Factory<AccessLog>
 */
final class AccessLogFactory extends Factory
{
    protected $model = AccessLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'area_id' => static fn (array $attributes): string => AccessArea::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'device_id' => static fn (array $attributes): string => AccessDevice::factory()->create(['tenant_id' => $attributes['tenant_id'], 'area_id' => $attributes['area_id']])->id,
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'credential_id' => static fn (array $attributes): string => Credential::factory()->create(['tenant_id' => $attributes['tenant_id'], 'member_id' => $attributes['member_id']])->id,
            'direction' => 'in',
            'decision' => 'allow',
            'reason_code' => AccessReason::Granted,
            'occurred_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
        ];
    }
}
