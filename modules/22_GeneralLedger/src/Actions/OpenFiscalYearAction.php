<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;

/**
 * Opens a calendar fiscal year with its twelve monthly periods. Repeating it changes nothing, and it never touches
 * the status of a period that already exists.
 */
final class OpenFiscalYearAction
{
    public function execute(string $tenantId, int $year): FiscalYear
    {
        return DB::transaction(function () use ($tenantId, $year): FiscalYear {
            $first = CarbonImmutable::create($year, 1, 1);

            $fiscalYear = FiscalYear::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'year_code' => "FY{$year}"],
                [
                    'id' => (string) Str::uuid7(),
                    'start_date' => $first->toDateString(),
                    'end_date' => $first->endOfYear()->toDateString(),
                    'status' => 'open',
                ]
            );

            for ($month = 1; $month <= 12; $month++) {
                $start = $first->month($month);

                FiscalPeriod::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'fiscal_year_id' => $fiscalYear->id, 'period_number' => $month],
                    [
                        'id' => (string) Str::uuid7(),
                        'period_name' => $start->format('F Y'),
                        'start_date' => $start->toDateString(),
                        'end_date' => $start->endOfMonth()->toDateString(),
                        'status' => 'open',
                    ]
                );
            }

            return $fiscalYear;
        });
    }
}
