<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Services;

use App\Domain\Procurement\PurchaseRequisition\ValueObjects\ApprovalTierSpec;
use Modules\Procurement\Support\Decimal;

/**
 * Resolves cumulative multi-level delegation tiers for CHF/EUR amounts from Mod 006 matrices or configured defaults.
 */
final class DelegationThresholdDomainService
{
    /**
     * Filters and orders approval tiers matching the requisition amount and currency.
     *
     * @param  list<array{tier_level?: int, level?: int, approver_type: string, approver_value: string, label?: ?string, threshold_min?: string|int|float|null, threshold_max?: string|int|float|null}>  $rawTiers
     * @return list<ApprovalTierSpec>
     */
    public function resolveMatchingTiers(string $amount, string $currency, ?array $rawTiers = null): array
    {
        $normalizedAmount = Decimal::of($amount, 4);
        $normalizedCurrency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'EUR';
        $tiers = $rawTiers ?? $this->defaultConfiguredTiers();

        $matched = [];
        foreach ($tiers as $tier) {
            $min = isset($tier['threshold_min']) && $tier['threshold_min'] !== ''
                ? Decimal::of($tier['threshold_min'], 4)
                : null;
            $max = isset($tier['threshold_max']) && $tier['threshold_max'] !== ''
                ? Decimal::of($tier['threshold_max'], 4)
                : null;

            if ($min !== null && Decimal::cmp($normalizedAmount, $min, 4) < 0) {
                continue;
            }

            if ($max !== null && Decimal::cmp($normalizedAmount, $max, 4) > 0) {
                continue;
            }

            $level = (int) ($tier['level'] ?? $tier['tier_level'] ?? (count($matched) + 1));
            $approverType = (string) $tier['approver_type'];
            $approverValue = (string) $tier['approver_value'];

            $matched[] = new ApprovalTierSpec(
                level: $level,
                approverType: $approverType,
                approverValue: $approverValue,
                label: (string) ($tier['label'] ?? ucfirst(str_replace('_', ' ', $approverValue))),
                thresholdMin: $min,
                thresholdMax: $max,
                currency: $normalizedCurrency,
            );
        }

        usort($matched, static fn (ApprovalTierSpec $a, ApprovalTierSpec $b): int => $a->level <=> $b->level);

        // If custom matrix had thresholds that excluded small amounts, fallback cleanly only when default tiers are used.
        if ($matched === [] && $rawTiers === null && $tiers !== []) {
            $first = $tiers[0];

            $matched[] = new ApprovalTierSpec(
                level: (int) ($first['level'] ?? $first['tier_level'] ?? 1),
                approverType: (string) $first['approver_type'],
                approverValue: (string) $first['approver_value'],
                label: (string) ($first['label'] ?? 'Department Manager'),
                thresholdMin: isset($first['threshold_min']) ? Decimal::of($first['threshold_min'], 4) : null,
                thresholdMax: isset($first['threshold_max']) ? Decimal::of($first['threshold_max'], 4) : null,
                currency: $normalizedCurrency,
            );
        }

        return $matched;
    }

    /**
     * @return list<array{level: int, approver_type: string, approver_value: string, label: string, threshold_min: ?string, threshold_max: ?string}>
     */
    private function defaultConfiguredTiers(): array
    {
        /** @var list<array{level: int, approver_type: string, approver_value: string, label: string, threshold_min: ?string, threshold_max: ?string}> $configured */
        $configured = config('procurement_requisitions.default_delegation_tiers', []);

        if ($configured !== []) {
            return $configured;
        }

        return [
            ['level' => 1, 'approver_type' => 'role', 'approver_value' => 'department_manager', 'label' => 'Department Manager', 'threshold_min' => '0.00', 'threshold_max' => null],
            ['level' => 2, 'approver_type' => 'role', 'approver_value' => 'division_director', 'label' => 'Division Director', 'threshold_min' => '1000.00', 'threshold_max' => null],
            ['level' => 3, 'approver_type' => 'role', 'approver_value' => 'cfo', 'label' => 'CFO / CEO', 'threshold_min' => '10000.01', 'threshold_max' => null],
        ];
    }
}
