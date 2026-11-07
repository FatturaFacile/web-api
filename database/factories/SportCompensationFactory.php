<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\SportCollaborator;
use Modules\Associations\Models\SportCompensation;

/**
 * @extends Factory<SportCompensation>
 */
final class SportCompensationFactory extends Factory
{
    protected $model = SportCompensation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'collaborator_id' => static fn (array $attributes): string => SportCollaborator::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'contract_id' => null,
            'period' => '2026-10',
            'paid_on' => '2026-11-05',
            'gross_amount' => '600.00',
            'contribution_base' => '0.00',
            'contribution_amount' => '0.00',
            'withholding_base' => '0.00',
            'withholding_amount' => '0.00',
            'hours' => '40.00',
            'payment_method' => 'bank',
        ];
    }
}
