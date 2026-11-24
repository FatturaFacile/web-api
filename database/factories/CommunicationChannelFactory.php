<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Communication\Models\CommunicationChannel;

/**
 * @extends Factory<CommunicationChannel>
 */
final class CommunicationChannelFactory extends Factory
{
    protected $model = CommunicationChannel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'channel' => 'email',
            'driver' => 'mail',
            'is_enabled' => true,
            'credentials' => null,
        ];
    }
}
