<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UsageMetricSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $metrics = [
                ['code' => 'API_CALLS', 'name' => 'API Requests', 'uom' => 'api_call', 'agg' => 'sum'],
                ['code' => 'CLOUD_STORAGE_GB', 'name' => 'GB Cloud Storage', 'uom' => 'gb', 'agg' => 'max'],
                ['code' => 'COMPUTE_HOURS', 'name' => 'Virtual Machine Compute Hours', 'uom' => 'hour', 'agg' => 'sum'],
                ['code' => 'ACTIVE_USERS', 'name' => 'Monthly Active Users (MAU)', 'uom' => 'unit', 'agg' => 'max'],
            ];

            foreach ($metrics as $m) {
                DB::table('sub_usage_metrics')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'metric_code' => $m['code']],
                    [
                        'id' => (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $m['name'],
                        'aggregation_type' => $m['agg'],
                        'unit_of_measure' => $m['uom'],
                        'is_active' => true,
                        'metadata' => json_encode(['category' => 'telemetry']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
