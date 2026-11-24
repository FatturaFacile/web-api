<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Procurement\Enums\ToleranceScope;
use Modules\Procurement\Models\InvoiceMatchToleranceRule;

/**
 * Maintains the tolerance rules of the invoice matching. A rule only carries the field its scope needs: a tenant rule
 * has no supplier or category, a supplier rule no category, a category rule no supplier.
 */
final class InvoiceMatchToleranceRuleService
{
    /**
     * @return Collection<int, InvoiceMatchToleranceRule>
     */
    public function all(): Collection
    {
        return InvoiceMatchToleranceRule::query()->orderBy('priority')->orderBy('name')->get()
            ->sortByDesc(static fn (InvoiceMatchToleranceRule $rule): int => $rule->scope->specificity())
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): InvoiceMatchToleranceRule
    {
        return InvoiceMatchToleranceRule::create(['tenant_id' => $tenantId] + $this->normalize($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InvoiceMatchToleranceRule $rule, array $data): InvoiceMatchToleranceRule
    {
        $rule->update($this->normalize($data));

        return $rule->refresh();
    }

    public function delete(InvoiceMatchToleranceRule $rule): void
    {
        $rule->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $scope = ToleranceScope::from((string) $data['scope']);

        return [
            'name' => $data['name'],
            'scope' => $scope->value,
            'supplier_id' => in_array($scope, [ToleranceScope::Supplier, ToleranceScope::Rule], true) ? ($data['supplier_id'] ?? null) : null,
            'item_category' => in_array($scope, [ToleranceScope::ItemCategory, ToleranceScope::Rule], true) ? ($data['item_category'] ?? null) : null,
            'match_type' => $data['match_type'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'price_tolerance_percent' => $data['price_tolerance_percent'],
            'price_tolerance_amount' => $data['price_tolerance_amount'],
            'quantity_tolerance_percent' => $data['quantity_tolerance_percent'] ?? 0,
            'quantity_tolerance_amount' => $data['quantity_tolerance_amount'] ?? 0,
            'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
