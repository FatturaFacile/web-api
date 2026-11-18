<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryRevaluation;

/**
 * @extends Factory<InventoryRevaluation>
 */
class InventoryRevaluationFactory extends Factory
{
    protected $model = InventoryRevaluation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = (app(TenantContext::class)->getTenantId() ?? Tenant::first()?->id) ?? (string) Str::uuid7();
        $delta = fake()->randomFloat(2, 1, 500);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'source_type' => 'manual',
            'status' => 'posted',
            'posting_date' => now()->toDateString(),
            'total_inventory_delta' => $delta,
            'total_cogs_delta' => -$delta,
            'total_variance_delta' => 0,
            'lines' => [],
        ];
    }
}
