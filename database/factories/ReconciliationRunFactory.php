<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ReconciliationRun;

/**
 * @extends Factory<ReconciliationRun>
 */
final class ReconciliationRunFactory extends Factory
{
    protected $model = ReconciliationRun::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();
        $account = ChartOfAccount::first() ?? ChartOfAccount::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'reconciliation_type' => 'subledger_ar',
            'account_id' => $account->id,
            'status' => 'balanced',
            'gl_balance' => 5000.00,
            'subledger_balance' => 5000.00,
            'variance_amount' => 0.00,
            'reconciled_at' => now(),
            'notes' => 'Automated reconciliation run',
        ];
    }
}
