<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Reimbursement;

/**
 * @extends Factory<Reimbursement>
 */
final class ReimbursementFactory extends Factory
{
    protected $model = Reimbursement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'member_id' => static fn (array $attributes): string => Member::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'kind' => Reimbursement::Mileage,
            'incurred_on' => '2026-10-12',
            'description' => 'Trasferta per torneo regionale',
            'vehicle' => 'Fiat Panda',
            'plate' => strtoupper(fake()->bothify('??###??')),
            'distance_km' => '120.00',
            'unit_price' => '0.3500',
            'amount' => '42.00',
            'status' => Reimbursement::Submitted,
        ];
    }

    public function expense(): static
    {
        return $this->state([
            'kind' => Reimbursement::Expense,
            'description' => 'Acquisto materiale sportivo',
            'vehicle' => null,
            'plate' => null,
            'distance_km' => null,
            'unit_price' => null,
            'amount' => '89.90',
        ]);
    }
}
