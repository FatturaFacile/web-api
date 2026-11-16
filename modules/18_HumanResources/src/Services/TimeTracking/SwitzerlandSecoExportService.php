<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates Switzerland SECO (Staatssekretariat für Wirtschaft) working-time CSV per Art. 46 LL
 * and Art. 73/73a/73b OLL 1. Semicolon-delimited, UTF-8-BOM, one row per work day.
 */
final class SwitzerlandSecoExportService
{
    private const TIMEZONE = 'Europe/Zurich';

    private const BOM = "\xEF\xBB\xBF";

    /**
     * @return string UTF-8-BOM CSV content ready to send as a file download
     */
    public function generate(string $tenantId, ?string $employeeId, string $from, string $to): string
    {
        $rows = DB::table('time_daily_records as dr')
            ->join('users as u', 'u.id', '=', 'dr.user_id')
            ->where('dr.tenant_id', $tenantId)
            ->whereBetween('dr.work_date', [$from, $to])
            ->when($employeeId, fn ($q) => $q->where('dr.user_id', $employeeId))
            ->leftJoin('time_recording_profiles as p', function ($join) use ($tenantId): void {
                $join->on('p.user_id', '=', 'dr.user_id')
                    ->where('p.tenant_id', $tenantId);
            })
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
                'p.is_regular_night_worker',
            ])
            ->get();

        $lines = [];
        $lines[] = implode(';', [
            'Mitarbeiter-Nr',
            'Name',
            'Datum',
            'Arbeitsbeginn',
            'Arbeitsende',
            'Pausen-Min',
            'Arbeitszeit-Min',
            'Nachtarbeit',
            'Bemerkung',
        ]);

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->work_date)->format('d.m.Y');
            $startTime = $row->start_time
                ? CarbonImmutable::parse($row->start_time)->setTimezone(self::TIMEZONE)->format('H:i')
                : '';
            $endTime = $row->end_time
                ? CarbonImmutable::parse($row->end_time)->setTimezone(self::TIMEZONE)->format('H:i')
                : '';

            // Break minutes = gross time - worked_minutes (if both boundaries known)
            $breakMinutes = 0;
            if ($row->start_time && $row->end_time && $row->worked_minutes > 0) {
                $start = CarbonImmutable::parse($row->start_time);
                $end = CarbonImmutable::parse($row->end_time);
                $gross = (int) $start->diffInMinutes($end);
                $breakMinutes = max(0, $gross - (int) $row->worked_minutes);
            }

            $isNight = $row->is_regular_night_worker ? 'J' : 'N';

            $lines[] = implode(';', [
                $this->escapeCsv((string) $row->user_id),
                $this->escapeCsv((string) $row->employee_name),
                $date,
                $startTime,
                $endTime,
                (string) $breakMinutes,
                (string) ((int) $row->worked_minutes),
                $isNight,
                $this->escapeCsv((string) ($row->note ?? '')),
            ]);
        }

        return self::BOM.implode("\r\n", $lines)."\r\n";
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
