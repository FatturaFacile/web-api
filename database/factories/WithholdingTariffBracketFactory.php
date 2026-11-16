<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Models\Payroll\WithholdingTariffBracket;

/**
 * @extends Factory<WithholdingTariffBracket>
 */
class WithholdingTariffBracketFactory extends Factory
{
    protected $model = WithholdingTariffBracket::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'withholding_tariff_id' => WithholdingTariff::factory(),
            'tenant_id' => fn (array $attributes) => WithholdingTariff::withoutGlobalScopes()->find($attributes['withholding_tariff_id'])?->tenant_id,
            'income_from_rappen' => 0,
            'income_to_rappen' => null,
            'rate_ppm' => 100_000,
            'fixed_amount_rappen' => 0,
        ];
    }
}
