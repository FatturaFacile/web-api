<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pricing\Models\MarginOverrideApproval;
use Modules\Pricing\Models\MarginPolicy;

/**
 * Margin guardrails: the theoretical gross margin of a line, the policy that applies to it and, for a document, whether it
 * may be confirmed. A blocked document needs a margin override approval whose margin it still meets.
 */
final class MarginGuardrail
{
    public const string Ok = 'OK';

    public const string Warning = 'WARNING';

    public const string Blocked = 'BLOCKED';

    /** Percentage points above the minimum within which a line is only a warning. */
    public const string WarningBand = '5';

    /**
     * ((net - cost) / net) * 100, or null when the price is not positive (free goods have no meaningful margin).
     */
    public function marginPercent(string $netUnitPrice, string $cost): ?string
    {
        if (bccomp($netUnitPrice, '0', 6) <= 0) {
            return null;
        }

        return bcmul(bcdiv(bcsub($netUnitPrice, $cost, 6), $netUnitPrice, 8), '100', 2);
    }

    /**
     * The reference cost of a product for the cost basis of a policy.
     */
    public function referenceCost(Product $product, string $costBasis): string
    {
        if ($costBasis === MarginPolicy::AverageCost) {
            $average = DB::table('stock_items')
                ->where('tenant_id', $product->tenant_id)
                ->where('sku', $product->sku)
                ->value('current_moving_average_cost');

            if ($average !== null && bccomp((string) $average, '0', 4) > 0) {
                return bcadd((string) $average, '0', 4);
            }
        }

        return bcadd((string) ($product->cost_price ?? '0'), '0', 4);
    }

    /**
     * The most specific policy: product, then price list, then category, then global.
     */
    public function policyFor(Product $product, ?string $priceListId): ?MarginPolicy
    {
        $policies = MarginPolicy::query()->get();

        $matches = static fn (string $scope, ?string $id): ?MarginPolicy => $policies->first(
            static fn (MarginPolicy $policy): bool => $policy->scope === $scope && ($scope === MarginPolicy::Global || ($id !== null && (string) $policy->scope_id === $id)),
        );

        return $matches(MarginPolicy::Product, (string) $product->id)
            ?? $matches(MarginPolicy::PriceList, $priceListId)
            ?? $matches(MarginPolicy::Category, $product->category_id === null ? null : (string) $product->category_id)
            ?? $matches(MarginPolicy::Global, null);
    }

    /**
     * @return array{margin_percent: ?string, status: string, threshold_percent: ?string, cost: string, block_on_violation: bool, requires_approval_role: ?string}
     */
    public function evaluate(Product $product, string $netUnitPrice, ?string $priceListId = null, ?string $overrideCost = null): array
    {
        $policy = $this->policyFor($product, $priceListId);
        $cost = $overrideCost !== null && bccomp($overrideCost, '0', 4) > 0
            ? bcadd($overrideCost, '0', 4)
            : $this->referenceCost($product, $policy->cost_basis ?? MarginPolicy::LastPurchaseCost);
        $margin = $this->marginPercent($netUnitPrice, $cost);

        $result = [
            'margin_percent' => $margin,
            'status' => self::Ok,
            'threshold_percent' => $policy === null ? null : bcadd((string) $policy->min_margin_percent, '0', 2),
            'cost' => $cost,
            'block_on_violation' => $policy->block_on_violation ?? false,
            'requires_approval_role' => $policy?->requires_approval_role,
        ];

        if ($policy === null) {
            return $result;
        }

        $threshold = (string) $policy->min_margin_percent;
        // A line sold for nothing against a real cost is below any minimum margin.
        $below = $margin === null ? bccomp($cost, '0', 4) > 0 : bccomp($margin, $threshold, 2) < 0;

        if ($below) {
            $result['status'] = $policy->block_on_violation ? self::Blocked : self::Warning;
        } elseif ($margin !== null && bccomp($margin, bcadd($threshold, self::WarningBand, 2), 2) < 0) {
            $result['status'] = self::Warning;
        }

        return $result;
    }

    /**
     * Worst status and its margin over the evaluated lines.
     *
     * @param  Collection<int, array<string, mixed>>  $evaluations
     * @return array{status: string, margin_percent: ?string, threshold_percent: ?string}
     */
    public function summarize(Collection $evaluations): array
    {
        $rank = [self::Ok => 0, self::Warning => 1, self::Blocked => 2];
        $worstRank = $evaluations->map(static fn (array $e): int => $rank[$e['status']])->max() ?? 0;
        $status = (string) array_search($worstRank, $rank, true);

        $candidates = $evaluations->filter(static fn (array $e): bool => $e['status'] === $status && $e['margin_percent'] !== null);
        $lowest = $candidates->sortBy(static fn (array $e): float => (float) $e['margin_percent'])->first();

        return [
            'status' => $status,
            'margin_percent' => $lowest['margin_percent'] ?? null,
            'threshold_percent' => $lowest['threshold_percent'] ?? $evaluations->first(static fn (array $e): bool => $e['status'] === $status)['threshold_percent'] ?? null,
        ];
    }

    /**
     * Evaluate the lines of a stored sales document.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function evaluateDocument(object $document): Collection
    {
        $evaluations = collect();

        foreach ($document->items as $item) {
            $product = $item->product_id === null ? null : Product::query()->find($item->product_id);

            if ($product === null) {
                continue;
            }

            $net = bcmul((string) $item->unit_price, bcsub('1', bcdiv((string) ($item->discount_percentage ?? '0'), '100', 8), 8), 4);
            $evaluations->push($this->evaluate($product, $net, $document->price_list_id ?? null, (string) ($item->cost_price ?? '0')));
        }

        return $evaluations;
    }

    /**
     * Refuse to confirm a document with a blocked line unless a still valid override approval exists.
     *
     * @throws BusinessRuleException
     */
    public function assertDocumentAllowed(string $documentType, object $document): void
    {
        $summary = $this->summarize($this->evaluateDocument($document));

        if ($summary['status'] !== self::Blocked) {
            return;
        }

        $approval = MarginOverrideApproval::query()
            ->where('document_type', $documentType)
            ->where('document_id', (string) $document->id)
            ->latest('approved_at')
            ->first();

        if ($approval !== null && bccomp((string) $summary['margin_percent'], bcsub((string) $approval->calculated_margin_percent, '0.01', 2), 2) >= 0) {
            return;
        }

        throw new BusinessRuleException(sprintf(
            'Margin %s%% is below the minimum %s%%: a margin override approval is required.',
            $summary['margin_percent'] ?? 'n/a',
            $summary['threshold_percent'] ?? 'n/a',
        ));
    }
}
