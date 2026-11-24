<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\SalesQuota;

/**
 * @extends Factory<SalesQuota>
 */
final class SalesQuotaFactory extends Factory
{
    protected $model = SalesQuota::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'user_id' => (string) Str::uuid7(),
            'period_start' => '2027-01-01',
            'period_end' => '2027-03-31',
            'target_amount' => '100000.00',
            'currency' => 'EUR',
        ];
    }
}
