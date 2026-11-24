<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Modules\Procurement\DTOs\MatchTolerance;
use Modules\Procurement\Enums\InvoiceMatchType;
use Modules\Procurement\Enums\ToleranceScope;
use Modules\Procurement\Models\InvoiceMatchToleranceRule;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Support\Decimal;

/**
 * Picks the tolerance of an invoice line. The narrowest active rule wins (named rule, supplier, item category, tenant);
 * inside a scope the lowest priority number, then the oldest rule. Without a rule the company settings apply (with the
 * price tolerance of the supplier when it has one).
 */
final class InvoiceMatchToleranceResolver
{
    /** @var array<string, list<InvoiceMatchToleranceRule>> */
    private array $rulesByTenant = [];

    public function __construct(private readonly ProcurementSettingsService $settings) {}

    public function resolve(string $tenantId, ?string $supplierId, ?string $itemCategory, InvoiceMatchType $type, ?string $currency = null): MatchTolerance
    {
        $applicable = array_filter(
            $this->rules($tenantId),
            fn (InvoiceMatchToleranceRule $rule): bool => $this->applies($rule, $supplierId, $itemCategory, $type, $currency),
        );

        usort($applicable, static function (InvoiceMatchToleranceRule $a, InvoiceMatchToleranceRule $b): int {
            return [$b->scope->specificity(), $a->priority, (string) $a->created_at] <=> [$a->scope->specificity(), $b->priority, (string) $b->created_at];
        });

        $rule = $applicable[0] ?? null;

        if ($rule !== null) {
            return new MatchTolerance(
                pricePercent: Decimal::of($rule->price_tolerance_percent, 2),
                priceAmount: Decimal::of($rule->price_tolerance_amount, 2),
                quantityPercent: Decimal::of($rule->quantity_tolerance_percent, 2),
                quantityAmount: Decimal::of($rule->quantity_tolerance_amount, 4),
                ruleId: (string) $rule->id,
                source: $rule->scope->value,
            );
        }

        $company = $this->settings->forTenant($tenantId);
        $price = $this->settings->priceTolerance($tenantId, $supplierId !== null ? Supplier::query()->find($supplierId) : null);

        return new MatchTolerance(
            pricePercent: $price['percent'],
            priceAmount: $price['amount'],
            quantityPercent: Decimal::of($company->over_receipt_tolerance_percent, 2),
            quantityAmount: '0.0000',
        );
    }

    public function flush(): void
    {
        $this->rulesByTenant = [];
    }

    private function applies(InvoiceMatchToleranceRule $rule, ?string $supplierId, ?string $itemCategory, InvoiceMatchType $type, ?string $currency): bool
    {
        if ($rule->match_type !== null && $rule->match_type !== $type->value) {
            return false;
        }

        if ($rule->currency !== null && $currency !== null && strtoupper($rule->currency) !== strtoupper($currency)) {
            return false;
        }

        $supplierFits = $rule->supplier_id !== null && $rule->supplier_id === $supplierId;
        $categoryFits = $rule->item_category !== null && $itemCategory !== null && strcasecmp($rule->item_category, $itemCategory) === 0;

        return match ($rule->scope) {
            ToleranceScope::Tenant => true,
            ToleranceScope::Supplier => $supplierFits,
            ToleranceScope::ItemCategory => $categoryFits,
            ToleranceScope::Rule => ($rule->supplier_id === null || $supplierFits) && ($rule->item_category === null || $categoryFits),
        };
    }

    /**
     * @return list<InvoiceMatchToleranceRule>
     */
    private function rules(string $tenantId): array
    {
        return $this->rulesByTenant[$tenantId] ??= InvoiceMatchToleranceRule::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->all();
    }
}
