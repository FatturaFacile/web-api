<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Services;

use Illuminate\Support\Facades\DB;
use Modules\CorporateGroup\Models\CompanyDocumentSequence;

/**
 * Document numbering (sezionali) owned by each company: every company has its own counters per series and year.
 */
final class CompanySequenceService
{
    /**
     * Next number of a series, e.g. "ICSI-2026-00001". Must run inside the caller's transaction to stay gap-free.
     */
    public function next(string $tenantId, string $companyId, string $seriesCode, ?int $year = null): string
    {
        $year ??= (int) date('Y');

        return DB::transaction(function () use ($tenantId, $companyId, $seriesCode, $year): string {
            $sequence = CompanyDocumentSequence::query()
                ->where('company_id', $companyId)
                ->where('series_code', $seriesCode)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = CompanyDocumentSequence::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'series_code' => $seriesCode,
                    'year' => $year,
                    'prefix' => $seriesCode.'-'.$year.'-',
                    'last_number' => 0,
                    'padding' => 5,
                ]);
            }

            $sequence->last_number = (int) $sequence->last_number + 1;
            $sequence->save();

            return $sequence->prefix.str_pad((string) $sequence->last_number, (int) $sequence->padding, '0', STR_PAD_LEFT);
        });
    }
}
