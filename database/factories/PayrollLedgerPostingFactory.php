<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\PayrollLedgerPosting;

/**
 * @extends Factory<PayrollLedgerPosting>
 */
final class PayrollLedgerPostingFactory extends Factory
{
    protected $model = PayrollLedgerPosting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payroll_run_id' => static fn (array $attributes): string => (string) PayrollRunFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'month' => 10,
            ])->id,
            'status' => PayrollLedgerPosting::Pending,
            'run_date' => '2026-10-31',
            'currency' => 'CHF',
            'payload' => [
                'year' => 2026,
                'month' => 10,
                'totals' => ['gross_amount' => '18500.00', 'payout_amount' => '15320.40'],
                'amounts_by_code' => ['1000' => '18500.00'],
            ],
            'journal_entry_id' => null,
            'last_error' => null,
        ];
    }
}
