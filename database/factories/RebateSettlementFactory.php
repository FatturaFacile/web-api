<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateSettlement;

/**
 * @extends Factory<RebateSettlement>
 */
final class RebateSettlementFactory extends Factory
{
    protected $model = RebateSettlement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'agreement_id' => RebateAgreement::factory(),
            'amount' => '200.00',
            'currency' => 'EUR',
            'settlement_type' => RebateSettlement::CreditNoteDraft,
            'settled_on' => '2027-04-05',
        ];
    }
}
