<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates Italy LUL (Libro Unico del Lavoro) / Zucchetti-compatible CSV per Art. 39 D.L. 112/2008.
 * Semicolon-delimited, UTF-8, one row per work day.
 */
final class ItalyLulExportService
{
    private const TIMEZONE = 'Europe/Rome';

    private const DECIMAL_SEPARATOR = ','; // Italian locale

    /**
     * @return string UTF-8 CSV content ready to send as a file download
     */
    public function generate(string $tenantId, ?string $employeeId, string $from, string $to): string
    {
        $rows = DB::table('time_daily_records as dr')
            ->join('users as u', 'u.id', '=', 'dr.user_id')
            ->where('dr.tenant_id', $tenantId)
            ->whereBetween('dr.work_date', [$from, $to])
            ->when($employeeId, fn ($q) => $q->where('dr.user_id', $employeeId))
            ->orderBy('dr.user_id')
            ->orderBy('dr.work_date')
            ->select([
                'dr.user_id',
                'u.name as employee_name',
                'dr.work_date',
                'dr.start_time',
                'dr.end_time',
                'dr.worked_minutes',
                'dr.note',
            ])
            ->get();

        $lines = [];
        $lines[] = implode(';', [
            'MATRICOLA',
            'COGNOME_NOME',
            'DATA',
            'ORA_INIZIO',
            'ORA_FINE',
            'ORE_LAVORATE',
            'TIPO',
            'NOTE',
        ]);

        foreach ($rows as $row) {
            $workedHours = number_format((int) $row->worked_minutes / 60, 2, self::DECIMAL_SEPARATOR, '');
            $startTime = $row->start_time
                ? CarbonImmutable::parse($row->start_time)->setTimezone(self::TIMEZONE)->format('H:i')
                : '';
            $endTime = $row->end_time
                ? CarbonImmutable::parse($row->end_time)->setTimezone(self::TIMEZONE)->format('H:i')
                : '';

            $date = CarbonImmutable::parse($row->work_date)->format('d/m/Y');
            $matricola = substr(str_replace('-', '', (string) $row->user_id), 0, 8);

            $lines[] = implode(';', [
                $matricola,
                $this->escapeCsv((string) $row->employee_name),
                $date,
                $startTime,
                $endTime,
                $workedHours,
                'ORD', // All hours are ordinary by default; overtime split requires payroll data
                $this->escapeCsv((string) ($row->note ?? '')),
            ]);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
