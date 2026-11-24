<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\PayrollLedgerSetting;

/**
 * @extends Factory<PayrollLedgerSetting>
 */
final class PayrollLedgerSettingFactory extends Factory
{
    protected $model = PayrollLedgerSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'salary_expense_account_id' => static fn (array $attributes): string => (string) ChartOfAccountFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'type' => 'expense',
                'account_class' => 'expense',
                'account_type' => 'expense',
                'name' => 'Salari e stipendi',
            ])->id,
            'employee_payable_account_id' => static fn (array $attributes): string => (string) ChartOfAccountFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'type' => 'liability',
                'account_class' => 'liability',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'name' => 'Debiti verso dipendenti',
            ])->id,
            'employer_cost_account_id' => null,
            'expense_allowance_account_id' => null,
            'social_payable_account_id' => null,
            'tax_payable_account_id' => null,
            'non_cash_benefit_account_id' => null,
            'rounding_account_id' => null,
            'wage_type_accounts' => null,
        ];
    }
}
