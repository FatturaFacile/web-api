<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryBookExport;

/**
 * @extends Factory<InventoryBookExport>
 */
class InventoryBookExportFactory extends Factory
{
    protected $model = InventoryBookExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = (app(TenantContext::class)->getTenantId() ?? Tenant::first()?->id) ?? (string) Str::uuid7();
        $year = (int) now()->format('Y');
        $payload = (string) json_encode([
            'meta' => ['year' => $year, 'as_of' => "{$year}-12-31", 'company_id' => $tenantId, 'method_breakdown' => [], 'generated_at' => now()->toIso8601String()],
            'rows' => [],
            'totals' => ['quantity' => '0.0000', 'total_value' => '0.00'],
            'reconciliation' => ['gl_inventory_balance' => '0.00', 'difference' => '0.00'],
        ]);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'year' => $year,
            'as_of' => "{$year}-12-31",
            'status' => 'generated',
            'rows_count' => 0,
            'total_quantity' => 0,
            'total_value' => 0,
            'gl_inventory_balance' => 0,
            'difference' => 0,
            'method_breakdown' => [],
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'generated_at' => now(),
        ];
    }
}
