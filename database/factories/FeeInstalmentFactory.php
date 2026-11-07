<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Models\FeeInstalment;

/**
 * @extends Factory<FeeInstalment>
 */
final class FeeInstalmentFactory extends Factory
{
    protected $model = FeeInstalment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'fee_assignment_id' => static fn (array $attributes): string => FeeAssignmentFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'sequence' => 1,
            'due_on' => '2026-10-31',
            'amount' => '100.00',
            'paid_amount' => '0.00',
            'status' => InstalmentStatus::Open,
        ];
    }

    public function paid(): static
    {
        return $this->state(static fn (array $attributes): array => ['paid_amount' => $attributes['amount'], 'status' => InstalmentStatus::Paid]);
    }
}
