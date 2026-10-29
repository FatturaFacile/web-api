<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\CostCenter;

/**
 * @extends Factory<CostCenter>
 */
class CostCenterFactory extends Factory
{
    protected $model = CostCenter::class;

    public function definition(): array
    {
        $code = 'CC-'.strtoupper(Str::random(5));

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => $code,
            'name' => 'Cost Center '.$code,
            'gl_expense_account_code' => '6100',
            'gl_accrual_account_code' => '2250',
            'annual_budget' => 500000.00,
            'is_active' => true,
            'translations' => [
                'it' => 'Centro di Costo '.$code,
                'en' => 'Cost Center '.$code,
                'de' => 'Kostenstelle '.$code,
                'fr' => 'Centre de Coûts '.$code,
            ],
        ];
    }
}
