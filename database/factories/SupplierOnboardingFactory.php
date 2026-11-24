<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\SupplierOnboarding;

/**
 * @extends Factory<SupplierOnboarding>
 */
final class SupplierOnboardingFactory extends Factory
{
    protected $model = SupplierOnboarding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'supplier_id' => (string) Str::uuid7(),
            'status' => 'invited',
            'country' => 'IT',
            'invitation_email' => fake()->companyEmail(),
            'invited_at' => now(),
        ];
    }
}
