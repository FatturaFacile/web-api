<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\GeneralLedger\Models\VatTaxSetting;

/**
 * @extends Factory<VatTaxSetting>
 */
final class VatTaxSettingFactory extends Factory
{
    protected $model = VatTaxSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'periodicity' => 'monthly',
            'output_vat_account_ids' => [],
            'input_vat_account_ids' => [],
            'settlement_account_id' => static fn (array $attributes): string => (string) ChartOfAccountFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'company_id' => $attributes['tenant_id'],
                'type' => 'liability',
                'account_class' => 'liability',
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'name' => 'Erario c/liquidazione IVA',
            ])->id,
            'interest_expense_account_id' => null,
            'declarant_tax_code' => 'RSSMRA80A01F205X',
            'declarant_role_code' => '1',
        ];
    }
}
