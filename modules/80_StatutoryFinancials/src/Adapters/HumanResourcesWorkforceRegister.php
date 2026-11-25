<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Adapters;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\StatutoryFinancials\Contracts\WorkforceRegisterPort;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Workforce of Mod 072 (human resources): the average headcount is the mean of the twelve month-end headcounts of the year, by
 * employment type (the category of the notes), with the full-time equivalents from the FTE ratio of each employee.
 */
final class HumanResourcesWorkforceRegister implements WorkforceRegisterPort
{
    public function averageHeadcount(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('hr_employees')) {
            return ['available' => false, 'source' => 'Mod 072', 'rows' => [], 'total_headcount' => '0', 'total_fte' => '0'];
        }

        $employees = DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get(['hire_date', 'termination_date', 'employment_type', 'fte_ratio']);
        $points = [];
        $cursor = $from->endOfMonth();

        while ($cursor->lessThanOrEqualTo($to->endOfMonth()) && count($points) < 12) {
            $points[] = $cursor->min($to);
            $cursor = $cursor->addMonthNoOverflow()->endOfMonth();
        }

        $points = $points === [] ? [$to] : $points;
        $headcount = [];
        $fte = [];

        foreach ($points as $point) {
            foreach ($employees as $employee) {
                $hired = CarbonImmutable::parse($employee->hire_date);
                $left = $employee->termination_date !== null ? CarbonImmutable::parse($employee->termination_date) : null;

                if ($hired->greaterThan($point) || ($left !== null && $left->lessThan($point))) {
                    continue;
                }

                $category = (string) ($employee->employment_type ?: 'other');
                $headcount[$category] = Money::add($headcount[$category] ?? '0', '1');
                $fte[$category] = Money::add($fte[$category] ?? '0', Money::from($employee->fte_ratio));
            }
        }

        $divisor = (string) count($points);
        $rows = [];

        foreach ($headcount as $category => $total) {
            $rows[] = ['category' => $category, 'average_headcount' => Money::div($total, $divisor) ?? '0', 'average_fte' => Money::div($fte[$category], $divisor) ?? '0'];
        }

        return [
            'available' => true,
            'source' => 'Mod 072',
            'rows' => $rows,
            'total_headcount' => Money::sum(array_column($rows, 'average_headcount')),
            'total_fte' => Money::sum(array_column($rows, 'average_fte')),
        ];
    }
}
