<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiShare;

/**
 * @extends Factory<BiShare>
 */
final class BiShareFactory extends Factory
{
    protected $model = BiShare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'resource_type' => BiShare::Dashboard,
            'resource_id' => BiDashboard::factory(),
            'user_id' => User::factory(),
            'permission' => BiShare::View,
        ];
    }
}
