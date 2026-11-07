<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\SalesTeam;

/**
 * @extends Factory<SalesTeam>
 */
final class SalesTeamFactory extends Factory
{
    protected $model = SalesTeam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Area '.fake()->randomElement(['Nord-Ovest', 'Nord-Est', 'Centro', 'Sud', 'Ticino', 'Grandi Clienti']).' '.fake()->unique()->numerify('###'),
        ];
    }
}
