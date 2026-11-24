<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\DiscountThreshold;

/**
 * @extends Factory<DiscountThreshold>
 */
final class DiscountThresholdFactory extends Factory
{
    protected $model = DiscountThreshold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'from_discount_percent' => '15.00',
            'approver_role' => 'sales_manager',
        ];
    }
}
