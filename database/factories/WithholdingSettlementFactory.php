<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\WithholdingSettlement;

/**
 * @extends Factory<WithholdingSettlement>
 */
final class WithholdingSettlementFactory extends Factory
{
    protected $model = WithholdingSettlement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => 2026,
            'month' => 10,
            'currency' => 'EUR',
            'total_withheld' => '200.00',
            'total_f24' => '200.00',
            'due_date' => '2026-11-16',
            'status' => WithholdingSettlement::STATUS_CALCULATED,
            'payment_date' => null,
            'payment_journal_id' => null,
            'calculated_by' => null,
            'paid_by' => null,
        ];
    }
}
