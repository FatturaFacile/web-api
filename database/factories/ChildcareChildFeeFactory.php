<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\ChildFee;

/**
 * @extends Factory<ChildFee>
 */
final class ChildcareChildFeeFactory extends Factory
{
    protected $model = ChildFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'child_id' => static fn (array $attributes): string => (string) ChildcareChildFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'fee_plan_id' => static fn (array $attributes): string => (string) ChildcareFeePlanFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'valid_from' => '2026-09-01',
            'valid_to' => null,
        ];
    }
}
