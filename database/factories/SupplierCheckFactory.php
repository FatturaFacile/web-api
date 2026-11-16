<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\SupplierCheck;

/**
 * @extends Factory<SupplierCheck>
 */
final class SupplierCheckFactory extends Factory
{
    protected $model = SupplierCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'supplier_id' => (string) Str::uuid7(),
            'check_type' => 'vies_vat',
            'result' => 'passed',
            'checked_at' => now()->toDateString(),
        ];
    }
}
