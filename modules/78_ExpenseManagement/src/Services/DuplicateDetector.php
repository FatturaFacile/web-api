<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;

/**
 * Finds a receipt already claimed: the same file (SHA-256) or the same normalised merchant, date and amount in the base
 * currency. Cancelled reports do not count.
 */
final class DuplicateDetector
{
    public static function normalizeMerchant(string $name): string
    {
        $ascii = strtolower(Str::ascii($name));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /**
     * @return list<array{code: string, severity: string, params: array<string, mixed>, matched_line_id: string, matched_report_number: string|null}>
     */
    public function find(?string $sha256, string $merchantName, CarbonInterface $date, string $amountInBase, ?string $excludeLineId = null): array
    {
        $warnings = [];
        $seen = [];

        if ($sha256 !== null && $sha256 !== '') {
            foreach ($this->base($excludeLineId)->where('receipt_sha256', $sha256)->limit(5)->get() as $line) {
                $seen[$line->id] = true;
                $warnings[] = $this->warning('duplicate_receipt_hash', 'blocking', $line);
            }
        }

        $merchant = self::normalizeMerchant($merchantName);

        if ($merchant !== '') {
            $matches = $this->base($excludeLineId)
                ->where('normalized_merchant', $merchant)
                ->whereDate('expense_date', $date->toDateString())
                ->where('amount_in_base_currency', $amountInBase)
                ->limit(5)->get();

            foreach ($matches as $line) {
                if (! isset($seen[$line->id])) {
                    $warnings[] = $this->warning('duplicate_merchant_date_amount', 'warning', $line);
                }
            }
        }

        return $warnings;
    }

    /**
     * @return Builder<ExpenseLine>
     */
    private function base(?string $excludeLineId)
    {
        $query = ExpenseLine::query()
            ->with('report:id,report_number,status')
            ->whereHas('report', fn ($report) => $report->where('status', '!=', ReportStatus::Cancelled->value));

        return $excludeLineId === null ? $query : $query->where('id', '!=', $excludeLineId);
    }

    /**
     * @return array{code: string, severity: string, params: array<string, mixed>, matched_line_id: string, matched_report_number: string|null}
     */
    private function warning(string $code, string $severity, ExpenseLine $line): array
    {
        /** @var ExpenseReport|null $report */
        $report = $line->report;

        return [
            'code' => $code,
            'severity' => $severity,
            'params' => ['report_number' => $report?->report_number, 'merchant' => $line->merchant_name, 'amount' => $line->amount_in_base_currency],
            'matched_line_id' => (string) $line->id,
            'matched_report_number' => $report?->report_number,
        ];
    }
}
