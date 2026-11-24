<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Returns\Models\SupplierReturn;

/**
 * @extends Factory<SupplierReturn>
 */
final class SupplierReturnFactory extends Factory
{
    protected $model = SupplierReturn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'return_number' => 'RTV-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'supplier_id' => Supplier::factory(),
            'status' => SupplierReturn::Draft,
            'currency' => 'EUR',
            'credit_expected' => '0.00',
        ];
    }
}
