<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * Validates paired competence_start_date and competence_end_date fields:
 * both must be provided together (or both omitted/null), and end >= start.
 */
trait ValidatesCompetenceDates
{
    /**
     * @return array<string, list<string>>
     */
    protected function competenceDateRules(string $prefix = ''): array
    {
        return [
            "{$prefix}competence_start_date" => ['nullable', 'date', "required_with:{$prefix}competence_end_date"],
            "{$prefix}competence_end_date" => ['nullable', 'date', "required_with:{$prefix}competence_start_date", "after_or_equal:{$prefix}competence_start_date"],
        ];
    }

    /**
     * Enforces per-row pair completeness and ordering across line arrays (`items` or `lines`).
     */
    protected function validateCompetenceDatePairs(Validator $validator, string $arrayKey): void
    {
        $rows = $this->input($arrayKey);

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $start = $row['competence_start_date'] ?? null;
            $end = $row['competence_end_date'] ?? null;
            $hasStart = $start !== null && $start !== '';
            $hasEnd = $end !== null && $end !== '';

            if ($hasStart && ! $hasEnd) {
                $validator->errors()->add("{$arrayKey}.{$index}.competence_end_date", __('general_ledger_errors.accrual.competence_dates_pair'));

                continue;
            }

            if ($hasEnd && ! $hasStart) {
                $validator->errors()->add("{$arrayKey}.{$index}.competence_start_date", __('general_ledger_errors.accrual.competence_dates_pair'));

                continue;
            }

            if ($hasStart) {
                try {
                    $startDate = CarbonImmutable::parse((string) $start)->startOfDay();
                    $endDate = CarbonImmutable::parse((string) $end)->startOfDay();

                    if ($endDate->lessThan($startDate)) {
                        $validator->errors()->add("{$arrayKey}.{$index}.competence_end_date", __('general_ledger_errors.accrual.competence_end_before_start'));
                    }
                } catch (\Throwable) {
                    // Date format errors are already reported by Laravel's 'date' rule.
                }
            }
        }
    }
}
