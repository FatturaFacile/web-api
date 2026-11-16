<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Returns\Models\Warranty;
use Modules\Returns\Models\WarrantyClaim;

/**
 * @extends Factory<WarrantyClaim>
 */
final class WarrantyClaimFactory extends Factory
{
    protected $model = WarrantyClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'claim_number' => 'CLM-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'warranty_id' => Warranty::factory(),
            'claim_date' => now()->toDateString(),
            'description' => fake()->sentence(),
            'status' => WarrantyClaim::Open,
            'is_valid' => true,
        ];
    }
}
