<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\AccountTerritory;
use Modules\CRM\Models\SalesTerritory;

/**
 * @extends Factory<AccountTerritory>
 */
final class AccountTerritoryFactory extends Factory
{
    protected $model = AccountTerritory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'account_id' => Account::factory(),
            'territory_id' => SalesTerritory::factory(),
            'source' => 'manual',
            'assigned_at' => now(),
        ];
    }
}
