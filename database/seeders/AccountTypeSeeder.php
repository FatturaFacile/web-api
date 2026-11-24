<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountTypeSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $templates = [
            [
                'code' => 'TPL_IT_CEE',
                'name' => 'Italian Statutory CEE Schema (Codice Civile OIC / Schema CEE)',
                'source' => 'system',
                'desc' => 'Conforms to Italian Codice Civile Art. 2424 / 2425',
            ],
            [
                'code' => 'TPL_CH_KMU',
                'name' => 'Swiss SME Standard Chart of Accounts (Schweizer Kontenrahmen KMU)',
                'source' => 'system',
                'desc' => 'Conforms to Swiss Code of Obligations OR 957-963b',
            ],
            [
                'code' => 'TPL_IFRS',
                'name' => 'IFRS Global Account Classification',
                'source' => 'system',
                'desc' => 'Conforms to IAS 1 Presentation of Financial Statements',
            ],
        ];

        foreach ($templates as $t) {
            DB::table('posting_templates')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'code' => $t['code'],
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $tenantId,
                    'name' => $t['name'],
                    'source_event_type' => $t['source'],
                    'description' => $t['desc'],
                    'template_payload' => json_encode(['version' => '2.0']),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
