<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\TimeBadge;

/**
 * @extends Factory<TimeBadge>
 */
final class TimeBadgeFactory extends Factory
{
    protected $model = TimeBadge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'badge_code' => strtoupper(fake()->unique()->bothify('RFID-########')),
            'is_active' => true,
        ];
    }
}
