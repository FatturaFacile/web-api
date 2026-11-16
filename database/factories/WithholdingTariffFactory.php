<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Models\Payroll\WithholdingTariffBracket;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * @extends Factory<WithholdingTariff>
 */
class WithholdingTariffFactory extends Factory
{
    protected $model = WithholdingTariff::class;

    public function definition(): array
    {
        $year = (int) now()->format('Y');

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'canton' => 'TI',
            'tariff_code' => 'A0N',
            'valid_from' => "{$year}-01-01",
            'valid_to' => null,
            'source' => 'manual',
            'description' => 'Factory tariff',
        ];
    }

    /**
     * Single open bracket applying the given percentage to every income.
     */
    public function flatRate(string $ratePercent = '10.00'): static
    {
        return $this->afterCreating(function (WithholdingTariff $tariff) use ($ratePercent): void {
            WithholdingTariffBracket::factory()->create([
                'tenant_id' => $tariff->tenant_id,
                'withholding_tariff_id' => $tariff->id,
                'income_from_rappen' => 0,
                'income_to_rappen' => null,
                'rate_ppm' => Rappen::percentToPpm($ratePercent),
            ]);
        });
    }
}
