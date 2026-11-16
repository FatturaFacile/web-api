<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\BankChangeRequest;

/**
 * @extends Factory<BankChangeRequest>
 */
final class BankChangeRequestFactory extends Factory
{
    protected $model = BankChangeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'supplier_id' => (string) Str::uuid7(),
            'source' => 'internal',
            'account_holder' => fake()->company(),
            'iban' => 'IT60X0542811101000000123456',
            'status' => BankChangeRequest::Pending,
        ];
    }
}
