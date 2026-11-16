<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosSession;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosSession>
 */
final class PosSessionFactory extends Factory
{
    protected $model = PosSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'pos_terminal_id' => fn (array $attributes): string => (string) PosTerminal::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => User::factory(),
            'opened_at' => now(),
            'opening_float' => '100.00',
            'status' => 'open',
        ];
    }
}
