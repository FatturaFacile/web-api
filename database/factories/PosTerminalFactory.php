<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosTerminal>
 */
final class PosTerminalFactory extends Factory
{
    protected $model = PosTerminal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'terminal_code' => strtoupper(fake()->unique()->bothify('CASSA-####')),
            'name' => 'Till '.fake()->numerify('##'),
            'is_active' => true,
        ];
    }
}
