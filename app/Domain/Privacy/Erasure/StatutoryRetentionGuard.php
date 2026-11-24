<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Enums\Privacy\DsarSubjectType;
use App\Services\Privacy\PersonalDataRetention;
use App\Services\Privacy\StatutoryRetentionCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspects the fiscal and payroll records linked to a data subject and computes the ten year lock window (Swiss CO
 * art. 958f / GeBüV, Italian c.c. art. 2220: the period counts from the end of the financial year of the record, as in
 * StatutoryRetentionCatalogue). Records inside the window must stay intact, so an erasure never touches them.
 */
final class StatutoryRetentionGuard
{
    /**
     * Fiscal sources per subject type: table, column that links the subject, date column (or year/month pair) and module.
     *
     * @var array<string, list<array{table: string, column: string, date: string|null, module: string}>>
     */
    private const array Sources = [
        'contact' => [
            ['table' => 'invoices', 'column' => 'contact_id', 'date' => 'date', 'module' => 'MOD_026_INVOICES'],
            ['table' => 'sales_invoices', 'column' => 'contact_id', 'date' => 'invoice_date', 'module' => 'MOD_026_INVOICES'],
            ['table' => 'general_ledger_lines', 'column' => 'contact_id', 'date' => 'posting_date', 'module' => 'MOD_012_GENERAL_LEDGER'],
        ],
        'employee' => [
            ['table' => 'payroll_payslips', 'column' => 'employee_id', 'date' => null, 'module' => 'MOD_076_PAYROLL'],
        ],
    ];

    public function inspect(string $tenantId, DsarSubjectType $type, ?string $entityId): FiscalLockReport
    {
        $group = match ($type) {
            DsarSubjectType::CustomerContact, DsarSubjectType::SupplierContact => 'contact',
            DsarSubjectType::Employee => 'employee',
            default => null,
        };

        if ($group === null || $entityId === null || $entityId === '') {
            return new FiscalLockReport([], null);
        }

        $today = CarbonImmutable::now()->startOfDay();
        $cutoffYear = $today->year - StatutoryRetentionCatalogue::FISCAL_RECORD_YEARS;
        $records = [];
        $latest = null;

        foreach (self::Sources[$group] as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }

            $query = DB::table($source['table'])->where($source['column'], $entityId);
            if (Schema::hasColumn($source['table'], 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }

            if ($source['table'] === 'payroll_payslips') {
                $query->where('year', '>=', $cutoffYear);
                $rows = (int) (clone $query)->count();
                $lastDate = $rows > 0 ? CarbonImmutable::create((int) (clone $query)->max('year'), 1, 1) : null;
            } else {
                if (! Schema::hasColumn($source['table'], (string) $source['date'])) {
                    continue;
                }

                $query->whereDate((string) $source['date'], '>=', CarbonImmutable::create($cutoffYear, 1, 1)->toDateString());
                $rows = (int) (clone $query)->count();
                $lastDate = $rows > 0 ? CarbonImmutable::parse((string) (clone $query)->max((string) $source['date'])) : null;
            }

            if ($rows === 0 || $lastDate === null) {
                continue;
            }

            $endsAt = StatutoryRetentionCatalogue::retentionEndsAt(PersonalDataRetention::FiscalRecord, $lastDate);
            $latest = $endsAt !== null && ($latest === null || $endsAt->isAfter($latest)) ? $endsAt : $latest;
            $records[] = [
                'table' => $source['table'],
                'module' => $source['module'],
                'rows' => $rows,
                'retention_ends_at' => (string) $endsAt?->toDateString(),
            ];
        }

        return new FiscalLockReport($records, $latest);
    }
}
