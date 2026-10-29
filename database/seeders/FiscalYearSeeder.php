<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $years = [
            ['code' => 'FY2026', 'start' => '2026-01-01', 'end' => '2026-12-31', 'status' => 'open'],
            ['code' => 'FY2027', 'start' => '2027-01-01', 'end' => '2027-12-31', 'status' => 'open'],
        ];

        foreach ($years as $y) {
            $existing = DB::table('fiscal_years')
                ->where('tenant_id', $tenantId)
                ->where('year_code', $y['code'])
                ->first();

            if (! $existing) {
                DB::table('fiscal_years')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'year_code' => $y['code'],
                    'start_date' => $y['start'],
                    'end_date' => $y['end'],
                    'status' => $y['status'],
                    'is_locked' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('fiscal_years')->where('id', $existing->id)->update([
                    'start_date' => $y['start'],
                    'end_date' => $y['end'],
                    'status' => $y['status'],
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
