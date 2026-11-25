<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Modules\ExpenseManagement\Models\ExpenseCategory;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpensePolicy;
use Modules\ExpenseManagement\Models\ExpensePolicyRule;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\Receipt;
use Modules\ExpenseManagement\Support\Decimal;

/**
 * Applies the expense policy of a jurisdiction to a line: item and daily caps, mandatory receipt, age and date checks.
 */
final class PolicyEvaluator
{
    public const string LowOcrConfidence = '0.6000';

    public function policyFor(string $jurisdiction): ?ExpensePolicy
    {
        return ExpensePolicy::query()
            ->where('jurisdiction', $jurisdiction)
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->with('rules')
            ->first();
    }

    /**
     * @return list<array{code: string, severity: string, params: array<string, mixed>}>
     */
    public function evaluate(ExpenseReport $report, ExpenseLine $line, ExpenseCategory $category, ?ExpensePolicy $policy): array
    {
        $violations = [];
        $amount = $line->amount_in_base_currency;
        $date = $line->expense_date;

        if ($date->isFuture()) {
            $violations[] = ['code' => 'future_date', 'severity' => 'blocking', 'params' => []];
        }

        if ($date->lt($report->period_start->copy()->startOfDay()) || $date->gt($report->period_end->copy()->endOfDay())) {
            $violations[] = ['code' => 'outside_report_period', 'severity' => 'warning', 'params' => ['from' => $report->period_start->toDateString(), 'to' => $report->period_end->toDateString()]];
        }

        if ($policy !== null && $policy->max_age_days !== null && $date->lt(now()->subDays($policy->max_age_days)->startOfDay())) {
            $violations[] = ['code' => 'expense_too_old', 'severity' => 'warning', 'params' => ['max_age_days' => $policy->max_age_days]];
        }

        $threshold = $policy->receipt_threshold ?? '0.00';

        if ($category->requires_receipt && $line->receipt_id === null && Decimal::cmp($amount, $threshold) > 0 && ! $category->is_mileage) {
            $violations[] = ['code' => 'receipt_required', 'severity' => 'blocking', 'params' => ['threshold' => $threshold]];
        }

        if ($line->receipt_id !== null) {
            $confidence = Receipt::query()->whereKey($line->receipt_id)->value('ocr_confidence');

            if ($confidence !== null && bccomp((string) $confidence, self::LowOcrConfidence, 4) < 0) {
                $violations[] = ['code' => 'low_ocr_confidence', 'severity' => 'warning', 'params' => ['confidence' => (string) $confidence]];
            }
        }

        foreach ($policy->rules ?? [] as $rule) {
            if ($rule->category_code !== null && $rule->category_code !== $category->code) {
                continue;
            }

            $violation = $rule->rule_type === ExpensePolicyRule::MaxPerDay
                ? $this->dailyCap($report, $line, $category, $rule)
                : (Decimal::cmp($amount, $rule->limit_amount) > 0
                    ? ['code' => 'exceeds_item_cap', 'severity' => $rule->severity, 'params' => ['limit' => $rule->limit_amount, 'amount' => $amount, 'category' => $category->code]]
                    : null);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return array{code: string, severity: string, params: array<string, mixed>}|null
     */
    private function dailyCap(ExpenseReport $report, ExpenseLine $line, ExpenseCategory $category, ExpensePolicyRule $rule): ?array
    {
        $query = ExpenseLine::query()
            ->whereDate('expense_date', $line->expense_date->toDateString())
            ->whereHas('report', fn ($reports) => $reports->where('user_id', $report->user_id)->where('status', '!=', 'cancelled'));

        if ($rule->category_code !== null) {
            $query->where('category_id', $category->id);
        }

        if ($line->exists) {
            $query->where('id', '!=', $line->id);
        }

        $total = Decimal::add((string) $query->sum('amount_in_base_currency'), $line->amount_in_base_currency);

        return Decimal::cmp($total, $rule->limit_amount) > 0
            ? ['code' => 'exceeds_daily_cap', 'severity' => $rule->severity, 'params' => ['limit' => $rule->limit_amount, 'total' => $total, 'category' => $category->code]]
            : null;
    }
}
