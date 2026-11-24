<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditDunningStep;
use Modules\CreditManagement\Models\CreditDunningStrategy;

/**
 * @extends Factory<CreditDunningStep>
 */
final class CreditDunningStepFactory extends Factory
{
    protected $model = CreditDunningStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'strategy_id' => CreditDunningStrategy::factory(),
            'step_order' => 1,
            'name' => 'First reminder',
            'days_after_due' => 5,
            'channel' => 'email',
            'fee_amount' => '0.00',
            'escalates' => false,
        ];
    }
}
