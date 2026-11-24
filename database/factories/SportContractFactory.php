<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\SportCollaborator;
use Modules\Associations\Models\SportContract;

/**
 * @extends Factory<SportContract>
 */
final class SportContractFactory extends Factory
{
    protected $model = SportContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'collaborator_id' => static fn (array $attributes): string => SportCollaborator::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'kind' => 'Lavoro sportivo autonomo',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'agreed_amount' => '600.00',
            'agreed_period' => 'month',
            'description' => 'Conduzione dei corsi di avviamento allo sport',
            'status' => 'active',
        ];
    }
}
