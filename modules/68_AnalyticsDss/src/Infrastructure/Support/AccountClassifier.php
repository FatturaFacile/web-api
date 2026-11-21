<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Support;

use Modules\AnalyticsDss\Domain\Enums\LedgerCategory;

/**
 * Maps a chart-of-accounts row to its analytical category. The reconciliation type of the account wins for receivables and
 * payables; the code prefixes of config/analytics_dss.php classify the rest.
 */
final class AccountClassifier
{
    public function classify(?string $code, ?string $type, ?string $reconciliationType): LedgerCategory
    {
        $type = strtolower((string) $type);
        $reconciliationType = strtolower((string) $reconciliationType);
        $code = (string) $code;

        if (in_array($type, ['revenue', 'income'], true)) {
            return LedgerCategory::Revenue;
        }

        if (in_array($reconciliationType, (array) config('analytics_dss.receivable_reconciliation_types', []), true)) {
            return LedgerCategory::Receivable;
        }

        if (in_array($reconciliationType, (array) config('analytics_dss.payable_reconciliation_types', []), true)) {
            return LedgerCategory::Payable;
        }

        if (in_array($type, ['expense', 'cost'], true)) {
            foreach ([LedgerCategory::Cogs, LedgerCategory::Depreciation, LedgerCategory::Interest, LedgerCategory::Tax] as $category) {
                if ($this->matches($code, $category)) {
                    return $category;
                }
            }

            return LedgerCategory::Opex;
        }

        foreach ([LedgerCategory::Cash, LedgerCategory::Receivable, LedgerCategory::Payable, LedgerCategory::Inventory] as $category) {
            if ($this->matches($code, $category)) {
                return $category;
            }
        }

        return LedgerCategory::Balance;
    }

    private function matches(string $code, LedgerCategory $category): bool
    {
        foreach ((array) config("analytics_dss.account_prefixes.{$category->value}", []) as $prefix) {
            if ($code !== '' && str_starts_with($code, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }
}
