<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\FamilyDiscount;

/**
 * @extends Factory<FamilyDiscount>
 */
final class FamilyDiscountFactory extends Factory
{
    protected $model = FamilyDiscount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Sconto secondo figlio',
            'from_position' => 2,
            'percentage' => '10.00',
            'is_active' => true,
        ];
    }
}
