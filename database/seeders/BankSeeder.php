<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BankSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $branches = [
            [
                'bank_name' => 'Intesa Sanpaolo S.p.A.',
                'branch_code' => '03069-01600',
                'branch_name' => 'Sede Centrale Milano',
                'bic_swift' => 'BCITITMM',
                'routing_number' => '03069',
                'address' => 'Piazza della Scala 6',
                'city' => 'Milano',
                'country' => 'IT',
            ],
            [
                'bank_name' => 'UniCredit S.p.A.',
                'branch_code' => '02008-01000',
                'branch_name' => 'Corporate Banking Tower',
                'bic_swift' => 'UNCRITM1',
                'routing_number' => '02008',
                'address' => 'Piazza Gae Aulenti 3',
                'city' => 'Milano',
                'country' => 'IT',
            ],
            [
                'bank_name' => 'BNP Paribas / BNL',
                'branch_code' => '01005-03382',
                'branch_name' => 'Roma Direzione Generale',
                'bic_swift' => 'BNLIITRR',
                'routing_number' => '01005',
                'address' => 'Viale Altiero Spinelli 30',
                'city' => 'Roma',
                'country' => 'IT',
            ],
            [
                'bank_name' => 'Deutsche Bank AG',
                'branch_code' => '03104-01600',
                'branch_name' => 'Milan Financial Branch',
                'bic_swift' => 'DEUTITMM',
                'routing_number' => '03104',
                'address' => 'Via Santa Margherita 4',
                'city' => 'Milano',
                'country' => 'IT',
            ],
            [
                'bank_name' => 'UBS Switzerland AG',
                'branch_code' => 'UBS-ZUR-001',
                'branch_name' => 'Zurich Paradeplatz Central',
                'bic_swift' => 'UBSWCHZH80A',
                'routing_number' => '00230',
                'address' => 'Paradeplatz 6',
                'city' => 'Zürich',
                'country' => 'CH',
            ],
        ];

        foreach ($branches as $branch) {
            $existing = DB::table('bank_branches')
                ->where('tenant_id', $tenantId)
                ->where('bic_swift', $branch['bic_swift'])
                ->where('branch_code', $branch['branch_code'])
                ->first();

            if ($existing) {
                DB::table('bank_branches')->where('id', $existing->id)->update([
                    'bank_name' => $branch['bank_name'],
                    'branch_name' => $branch['branch_name'],
                    'routing_number' => $branch['routing_number'],
                    'address' => $branch['address'],
                    'city' => $branch['city'],
                    'country' => $branch['country'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('bank_branches')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'bank_name' => $branch['bank_name'],
                    'branch_name' => $branch['branch_name'],
                    'bic_swift' => $branch['bic_swift'],
                    'branch_code' => $branch['branch_code'],
                    'routing_number' => $branch['routing_number'],
                    'address' => $branch['address'],
                    'city' => $branch['city'],
                    'country' => $branch['country'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
