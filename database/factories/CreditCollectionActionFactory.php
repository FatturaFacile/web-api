<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditCollectionAction;
use Modules\CreditManagement\Models\CreditCollectionCase;

/**
 * @extends Factory<CreditCollectionAction>
 */
final class CreditCollectionActionFactory extends Factory
{
    protected $model = CreditCollectionAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'case_id' => CreditCollectionCase::factory(),
            'action_type' => 'note',
            'performed_on' => now()->toDateString(),
            'fee_amount' => '0.00',
        ];
    }
}
