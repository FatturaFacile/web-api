<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\FivePerMilleAllocation;

/**
 * @extends Factory<FivePerMilleAllocation>
 */
final class FivePerMilleAllocationFactory extends Factory
{
    protected $model = FivePerMilleAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reference_year' => '2024',
            'received_on' => '2026-10-30',
            'amount' => number_format(fake()->randomFloat(2, 500, 15000), 2, '.', ''),
            'payment_method' => 'bank',
            'notes' => 'Contributo 5 per mille anno finanziario 2024',
        ];
    }
}
