<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class JournalTypeSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $batches = [
            ['batch_number' => 'BATCH-MANUAL-01', 'desc' => 'Manual General Adjustments Batch', 'module' => 'MANUAL'],
            ['batch_number' => 'BATCH-PROC-01', 'desc' => 'Procurement Payables & GR/IR Batch', 'module' => 'PROCUREMENT'],
            ['batch_number' => 'BATCH-INV-01', 'desc' => 'Inventory Valuation & COGS Batch', 'module' => 'INVENTORY'],
            ['batch_number' => 'BATCH-PRJ-01', 'desc' => 'Project Job Costing & WIP Batch', 'module' => 'PROJECTS'],
        ];

        foreach ($batches as $b) {
            DB::table('journal_batches')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'batch_number' => $b['batch_number'],
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $tenantId,
                    'description' => $b['desc'],
                    'source_module' => $b['module'],
                    'status' => 'open',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
