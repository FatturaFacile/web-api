<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\SupplierCostRule;
use Illuminate\Support\Collection;

/**
 * Applies the cost rules of the supplier to a received document. A rule with a keyword found in the line wins over the
 * rule without keyword; among keywords, the longest wins. Lines no rule reaches keep the default cost account.
 */
final class SupplierCostRuleApplier
{
    public function apply(PassiveInvoice $invoice): void
    {
        $rules = SupplierCostRule::where('supplier_id', $invoice->supplier_id)->get();

        if ($rules->isEmpty()) {
            return;
        }

        $generic = $rules->firstWhere('keyword', null);
        $keywords = $rules->whereNotNull('keyword')->sortByDesc(static fn (SupplierCostRule $rule): int => mb_strlen((string) $rule->keyword))->values();

        foreach ($invoice->lines()->get() as $line) {
            $rule = $this->matching($line, $keywords) ?? $generic;

            if ($rule !== null) {
                $line->update(['cost_account_id' => $rule->cost_account_id, 'cost_account_source' => 'rule']);
            }
        }

        if ($generic !== null) {
            $invoice->update(['cost_account_id' => $generic->cost_account_id, 'cost_account_source' => 'rule']);
        }
    }

    /**
     * @param  Collection<int, SupplierCostRule>  $keywords
     */
    private function matching(PassiveInvoiceLine $line, Collection $keywords): ?SupplierCostRule
    {
        return $keywords->first(static fn (SupplierCostRule $rule): bool => mb_stripos((string) $line->description, (string) $rule->keyword) !== false);
    }
}
