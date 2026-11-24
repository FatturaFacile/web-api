<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditPromiseToPay;

/**
 * @extends Factory<CreditPromiseToPay>
 */
final class CreditPromiseToPayFactory extends Factory
{
    protected $model = CreditPromiseToPay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'case_id' => CreditCollectionCase::factory(),
            'promised_amount' => '500.00',
            'promised_on' => now()->addDays(7)->toDateString(),
            'status' => CreditPromiseToPay::Pending,
        ];
    }
}
