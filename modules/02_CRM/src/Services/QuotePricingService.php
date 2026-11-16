<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Modules\CRM\Models\DiscountThreshold;
use Modules\CRM\Models\PricingRule;

/**
 * CPQ price calculation. The best matching pricing rule (highest discount, then lowest priority number) is applied
 * automatically, the sales person may add an extra discount, and the total discount decides which approver role the
 * quote needs, from the discount thresholds of the tenant.
 */
final class QuotePricingService
{
    /**
     * @return array{rule_discount_percent: string, rule_name: string|null, extra_discount_percent: string, discount_percent: string, net_total: string, required_approver_role: string|null}
     */
    public function price(string $tenantId, string $quantity, string $unitPrice, string $extraDiscountPercent = '0', ?string $partnerTierCode = null): array
    {
        $rule = $this->bestRule($tenantId, $quantity, $partnerTierCode);
        $ruleDiscount = number_format((float) ($rule?->discount_percent ?? 0), 2, '.', '');
        $extra = number_format((float) $extraDiscountPercent, 2, '.', '');
        $total = bccomp(bcadd($ruleDiscount, $extra, 2), '100', 2) > 0 ? '100.00' : bcadd($ruleDiscount, $extra, 2);
        $gross = bcmul($quantity, $unitPrice, 4);
        $net = bcsub($gross, bcdiv(bcmul($gross, $total, 4), '100', 4), 4);

        return [
            'rule_discount_percent' => $ruleDiscount,
            'rule_name' => $rule?->name,
            'extra_discount_percent' => $extra,
            'discount_percent' => $total,
            'net_total' => $this->round2($net),
            'required_approver_role' => $this->requiredApprover($tenantId, $total),
        ];
    }

    /**
     * The role that must approve a quote with this total discount, or null when it needs no approval.
     */
    public function requiredApprover(string $tenantId, string $discountPercent): ?string
    {
        return DiscountThreshold::query()
            ->where('tenant_id', $tenantId)
            ->where('from_discount_percent', '<=', $discountPercent)
            ->orderByDesc('from_discount_percent')
            ->value('approver_role');
    }

    private function bestRule(string $tenantId, string $quantity, ?string $partnerTierCode): ?PricingRule
    {
        $today = now()->toDateString();

        return PricingRule::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('min_quantity', '<=', $quantity)
            ->where(static fn ($query) => $query->whereNull('partner_tier_code')->when($partnerTierCode !== null, static fn ($inner) => $inner->orWhere('partner_tier_code', $partnerTierCode)))
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
            ->orderByDesc('discount_percent')
            ->orderBy('priority')
            ->first();
    }

    private function round2(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 4) >= 0 ? '0.005' : '-0.005', 2);
    }
}
