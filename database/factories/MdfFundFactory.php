<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\MdfFund;
use Modules\CRM\Models\Partner;

/**
 * @extends Factory<MdfFund>
 */
final class MdfFundFactory extends Factory
{
    protected $model = MdfFund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'partner_id' => Partner::factory(),
            'fiscal_year' => 2027,
            'allocated_amount' => '10000.00',
            'currency' => 'EUR',
        ];
    }
}
