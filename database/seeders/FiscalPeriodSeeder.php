<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FiscalPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $fy2026 = DB::table('fiscal_years')->where('tenant_id', $tenantId)->where('year_code', 'FY2026')->first();
        if (! $fy2026) {
            return;
        }

        $monthNames = [
            1 => 'January 2026 (Gennaio / Januar / Janvier)',
            2 => 'February 2026 (Febbraio / Februar / Février)',
            3 => 'March 2026 (Marzo / März / Mars)',
            4 => 'April 2026 (Aprile / April / Avril)',
            5 => 'May 2026 (Maggio / Mai / Mai)',
            6 => 'June 2026 (Giugno / Juni / Juin)',
            7 => 'July 2026 (Luglio / Juli / Juillet)',
            8 => 'August 2026 (Agosto / August / Août)',
            9 => 'September 2026 (Settembre / September / Septembre)',
            10 => 'October 2026 (Ottobre / Oktober / Octobre)',
            11 => 'November 2026 (Novembre / November / Novembre)',
            12 => 'December 2026 (Dicembre / Dezember / Décembre)',
            13 => 'Year-End Adjusting Period (Chiusura / Jahresabschluss / Clôture)',
        ];

        for ($m = 1; $m <= 12; $m++) {
            $start = sprintf('2026-%02d-01', $m);
            $end = date('Y-m-t', strtotime($start));

            $existing = DB::table('fiscal_periods')
                ->where('fiscal_year_id', $fy2026->id)
                ->where('period_number', $m)
                ->first();

            if (! $existing) {
                DB::table('fiscal_periods')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'fiscal_year_id' => $fy2026->id,
                    'period_number' => $m,
                    'period_name' => $monthNames[$m],
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => 'open',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('fiscal_periods')->where('id', $existing->id)->update([
                    'period_name' => $monthNames[$m],
                    'start_date' => $start,
                    'end_date' => $end,
                    'status' => 'open',
                    'updated_at' => now(),
                ]);
            }
        }

        // Period 13 Adjusting
        $existing13 = DB::table('fiscal_periods')
            ->where('fiscal_year_id', $fy2026->id)
            ->where('period_number', 13)
            ->first();

        if (! $existing13) {
            DB::table('fiscal_periods')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_year_id' => $fy2026->id,
                'period_number' => 13,
                'period_name' => $monthNames[13],
                'start_date' => '2026-12-31',
                'end_date' => '2026-12-31',
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('fiscal_periods')->where('id', $existing13->id)->update([
                'period_name' => $monthNames[13],
                'start_date' => '2026-12-31',
                'end_date' => '2026-12-31',
                'status' => 'open',
                'updated_at' => now(),
            ]);
        }
    }
}
