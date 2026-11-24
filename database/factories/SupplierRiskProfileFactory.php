<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\SupplierRiskProfile;

/**
 * @extends Factory<SupplierRiskProfile>
 */
final class SupplierRiskProfileFactory extends Factory
{
    protected $model = SupplierRiskProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'supplier_id' => (string) Str::uuid7(),
            'level' => 'none',
            'score' => 0,
            'reasons' => [],
            'evaluated_at' => now(),
        ];
    }
}
