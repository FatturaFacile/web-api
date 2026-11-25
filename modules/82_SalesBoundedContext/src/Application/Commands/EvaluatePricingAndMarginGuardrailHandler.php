<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\MarginGuardrailBreachedEvent;
use Modules\SalesBoundedContext\Models\SbcPricingGuardrail;

final readonly class EvaluatePricingAndMarginGuardrailHandler
{
    public function handle(EvaluatePricingAndMarginGuardrailCommand $command): SbcPricingGuardrail
    {
        $data = $command->payload;

        if ($command->guardrailId !== null && ($data['action'] ?? null) === 'approve_override') {
            $rule = SbcPricingGuardrail::query()->where('tenant_id', $command->tenantId)->findOrFail($command->guardrailId);
            $log = is_array($rule->breach_audit_log) ? $rule->breach_audit_log : [];
            $log[] = [
                'action' => 'manager_override_approved',
                'approved_by' => (string) ($data['approved_by_name'] ?? 'Sales Director'),
                'reason' => (string) ($data['reason'] ?? 'Strategic account retention'),
                'at' => now()->toIso8601String(),
            ];
            $rule->fill([
                'approval_status' => 'approved_override',
                'approved_by_name' => (string) ($data['approved_by_name'] ?? 'Sales Director'),
                'breach_audit_log' => $log,
            ]);
            $rule->save();

            return $rule;
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));
        $basePrice = DecimalMoney::of((float) ($data['base_unit_price'] ?? 100), $currency);
        $unitCost = DecimalMoney::of((float) ($data['unit_cost'] ?? 65), $currency);
        $cascading = array_values(array_map('floatval', is_array($data['cascading_discounts'] ?? null) ? $data['cascading_discounts'] : []));
        $promoDiscount = (float) ($data['promo_discount_pct'] ?? 0);

        $allDiscounts = [...$cascading];
        if ($promoDiscount > 0.0) {
            $allDiscounts[] = $promoDiscount;
        }

        $resolvedPrice = $basePrice->applyCascadingDiscounts($allDiscounts);
        $marginPct = $resolvedPrice->marginPercentage($unitCost);
        $minMarginPct = (float) ($data['min_margin_pct'] ?? 15.0);
        $breached = $marginPct < $minMarginPct;

        $rule = SbcPricingGuardrail::create([
            'tenant_id' => $command->tenantId,
            'rule_code' => (string) ($data['rule_code'] ?? 'PRC-'.strtoupper(Str::random(6))),
            'name' => (string) ($data['name'] ?? 'Tiered Cascading Price Rule'),
            'currency' => $currency,
            'customer_segment' => (string) ($data['customer_segment'] ?? 'enterprise'),
            'base_unit_price' => $basePrice->amount,
            'unit_cost' => $unitCost->amount,
            'cascading_discounts' => $cascading,
            'tier_breaks' => $data['tier_breaks'] ?? [],
            'promo_code' => $data['promo_code'] ?? null,
            'promo_discount_pct' => number_format($promoDiscount, 2, '.', ''),
            'resolved_net_price' => $resolvedPrice->amount,
            'calculated_margin_pct' => number_format($marginPct, 2, '.', ''),
            'min_margin_pct' => number_format($minMarginPct, 2, '.', ''),
            'guardrail_breached' => $breached,
            'approval_status' => $breached ? 'pending_manager_approval' : 'auto_approved',
            'breach_audit_log' => $breached ? [[
                'action' => 'guardrail_breached',
                'actual_margin_pct' => $marginPct,
                'min_margin_pct' => $minMarginPct,
                'at' => now()->toIso8601String(),
            ]] : [],
        ]);

        if ($breached) {
            MarginGuardrailBreachedEvent::dispatch($command->tenantId, (string) $rule->id, $marginPct, $minMarginPct);
        }

        return $rule;
    }
}
