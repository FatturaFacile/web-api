<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\SalesCommissionSetting;

/**
 * @extends Factory<SalesCommissionSetting>
 */
final class SalesCommissionSettingFactory extends Factory
{
    protected $model = SalesCommissionSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'expense_account_id' => static fn (array $attributes): string => (string) ChartOfAccountFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'type' => 'expense',
                'account_class' => 'expense',
                'account_type' => 'expense',
                'name' => 'Provvigioni passive',
            ])->id,
            'payable_account_id' => static fn (array $attributes): string => (string) ChartOfAccountFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'type' => 'liability',
                'account_class' => 'liability',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'name' => 'Debiti verso agenti',
            ])->id,
        ];
    }
}
