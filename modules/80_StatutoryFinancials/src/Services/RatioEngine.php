<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Domain\Strategy\FinancialSnapshot;
use Modules\StatutoryFinancials\Models\RatioDefinition;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Computes the ratios and crisis indicators of the seeded definitions from a financial snapshot, grades each one against its
 * thresholds (ok / warning / critical) and fills the seeded alert message of the locale.
 */
final class RatioEngine
{
    private const array Severity = ['n/a' => 0, 'ok' => 1, 'warning' => 2, 'critical' => 3];

    /**
     * @param  list<string>|null  $only  Restrict to some codes.
     * @return array{indicators: list<array<string, mixed>>, alert_level: string, alerts: list<array<string, string>>}
     */
    public function compute(string $family, FinancialSnapshot $snapshot, ?array $only = null): array
    {
        $definitions = RatioDefinition::query()->whereIn('family', [$family, 'ALL'])->orderBy('sort_order')->get();
        $byCode = [];

        foreach ($definitions as $definition) {
            if (! isset($byCode[$definition->code]) || $definition->tenant_id !== null) {
                $byCode[$definition->code] = $definition;
            }
        }

        $indicators = [];
        $alerts = [];
        $level = 'ok';

        foreach ($byCode as $code => $definition) {
            if ($only !== null && ! in_array($code, $only, true)) {
                continue;
            }

            $value = $this->value($code, $snapshot);
            $status = $this->status($definition, $value);
            $threshold = $status === 'critical' ? $definition->critical_threshold : $definition->warning_threshold;
            $locale = $snapshot->locale;
            $label = $definition->labels[$locale] ?? $definition->labels['en'] ?? $code;
            $message = null;

            if (in_array($status, ['warning', 'critical'], true)) {
                $template = $definition->alert_messages[$locale][$status] ?? $definition->alert_messages['en'][$status] ?? '';
                $message = str_replace(['{{label}}', '{{value}}', '{{threshold}}'], [$label, $value ?? '-', $threshold ?? '-'], $template);

                if ($definition->is_crisis_indicator) {
                    $alerts[] = ['code' => $code, 'status' => $status, 'message' => $message];
                }
            }

            if ($definition->is_crisis_indicator && self::Severity[$status] > self::Severity[$level]) {
                $level = $status;
            }

            $indicators[] = [
                'code' => $code,
                'label' => $label,
                'description' => $definition->descriptions[$locale] ?? $definition->descriptions['en'] ?? '',
                'unit' => $definition->unit,
                'value' => $value,
                'status' => $status,
                'direction' => $definition->direction,
                'warning_threshold' => $definition->warning_threshold,
                'critical_threshold' => $definition->critical_threshold,
                'is_crisis_indicator' => $definition->is_crisis_indicator,
                'message' => $message,
            ];
        }

        return ['indicators' => $indicators, 'alert_level' => $level, 'alerts' => $alerts];
    }

    public function value(string $code, FinancialSnapshot $s): ?string
    {
        $ebitda = Money::add($s->get('ebit'), $s->get('depreciation'));
        $pfn = Money::sub($s->get('financial_debt'), $s->get('cash'));

        return match ($code) {
            'ROE' => Money::percent($s->get('net_profit'), $s->get('equity')),
            'ROI' => Money::percent($s->get('ebit'), $s->get('total_assets')),
            'ROS' => Money::percent($s->get('ebit'), $s->get('revenue')),
            'EBITDA' => $ebitda,
            'PFN' => $pfn,
            'PFN_EBITDA' => Money::isPositive($ebitda) ? Money::div($pfn, $ebitda) : null,
            'CURRENT_RATIO' => Money::div($s->get('current_assets'), $s->get('current_liabilities')),
            'QUICK_RATIO' => Money::div(Money::sub($s->get('current_assets'), $s->get('inventories')), $s->get('current_liabilities')),
            'EQUITY_RATIO' => Money::percent($s->get('equity'), $s->get('total_assets')),
            'DSCR_6M' => Money::div(Money::add($s->get('expected_cash_flow_6m'), $s->get('opening_liquidity')), $s->get('debt_service_6m')),
            'NEGATIVE_EQUITY' => $s->get('equity'),
            'FIN_CHARGES_REVENUE' => Money::percent($s->get('financial_charges'), $s->get('revenue')),
            'EQUITY_DEBT' => Money::percent($s->get('equity'), $s->get('total_debt')),
            'CASH_FLOW_ASSETS' => Money::percent(Money::add($s->get('net_profit'), $s->get('depreciation')), $s->get('total_assets')),
            'LIQUIDITY_SHORT' => Money::percent($s->get('current_assets'), $s->get('current_liabilities')),
            'TAX_SOCIAL_DEBT_ASSETS' => Money::percent($s->get('tax_social_debt'), $s->get('total_assets')),
            'CAPITAL_LOSS_725' => Money::percent($s->get('equity'), $s->get('legal_capital_base')),
            'OVERINDEBTEDNESS_725B' => Money::percent($s->get('total_assets'), $s->get('total_debt')),
            default => null,
        };
    }

    private function status(RatioDefinition $definition, ?string $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        if ($definition->direction === null || ($definition->warning_threshold === null && $definition->critical_threshold === null)) {
            return 'ok';
        }

        $warning = $definition->warning_threshold;
        $critical = $definition->critical_threshold;

        if ($definition->direction === 'min') {
            if ($critical !== null && Money::compare($value, $critical) < 0) {
                return 'critical';
            }

            return $warning !== null && Money::compare($value, $warning) < 0 ? 'warning' : 'ok';
        }

        if ($critical !== null && Money::compare($value, $critical) > 0) {
            return 'critical';
        }

        return $warning !== null && Money::compare($value, $warning) > 0 ? 'warning' : 'ok';
    }
}
