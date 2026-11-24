<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Visitors\Models\VisitorKiosk;

/**
 * Kiosks built here carry the hash of the secret "kiosk-secret"; real secrets come from VisitorKioskService.
 *
 * @extends Factory<VisitorKiosk>
 */
final class VisitorKioskFactory extends Factory
{
    protected $model = VisitorKiosk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Reception '.fake()->randomElement(['ingresso', 'piano terra', 'magazzino', 'uffici']),
            'device_key' => Str::lower(Str::random(32)),
            'secret_hash' => hash('sha256', 'kiosk-secret'),
            'is_active' => true,
        ];
    }
}
