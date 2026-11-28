<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Ccii;

use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The monitor of the Art. 25-novies CCII thresholds of the qualified public creditors (INPS, INAIL, Agenzia delle Entrate, AdER) and of
 * the payroll and supplier arrears. Every threshold is a data row (`fin_public_creditor_thresholds`), so the law can change without code.
 *
 * A rule combines up to two limits: an absolute amount and a percentage of a basis (monthly payroll, non-overdue suppliers, prior-year
 * contributions or turnover). With `and` both must be exceeded, with `or` either one; an optional absolute limit breaches on its own.
 * The progress is how close the creditor is to the breach (100 % = breach), the traffic light turns yellow at the warning ratio.
 */
final readonly class PublicCreditorMonitor
{
    /**
     * @param  list<array<string, mixed>>  $rules  rows of the thresholds table (global and tenant), most specific first
     * @param  array{legal_form: string, employees_count: int, measured: array<string, string>, bases: array<string, string>}  $context
     * @return list<array<string, mixed>>
     */
    public function evaluate(array $rules, array $context): array
    {
        $byCode = [];

        foreach ($rules as $rule) {
            if (($rule['legal_form'] ?? null) !== null && $rule['legal_form'] !== $context['legal_form']) {
                continue;
            }

            $code = (string) $rule['creditor_code'];

            // A rule of the exact legal form beats a generic one.
            if (! isset($byCode[$code]) || (($byCode[$code]['legal_form'] ?? null) === null && ($rule['legal_form'] ?? null) !== null)) {
                $byCode[$code] = $rule;
            }
        }

        $result = [];

        foreach ($byCode as $code => $rule) {
            $result[] = $this->evaluateRule($code, $rule, $context);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array{legal_form: string, employees_count: int, measured: array<string, string>, bases: array<string, string>}  $context
     * @return array<string, mixed>
     */
    private function evaluateRule(string $code, array $rule, array $context): array
    {
        $measured = Decimal::round2($context['measured'][$code] ?? '0');
        $limits = [];

        $absolute = $context['employees_count'] === 0 && ($rule['absolute_threshold_no_employees'] ?? null) !== null
            ? (string) $rule['absolute_threshold_no_employees']
            : (($rule['absolute_threshold'] ?? null) !== null ? (string) $rule['absolute_threshold'] : null);

        if ($absolute !== null) {
            $limits[] = $absolute;
        }

        if (($rule['percent_threshold'] ?? null) !== null) {
            $basis = $context['bases'][(string) ($rule['percent_basis'] ?? '')] ?? '0';
            $limits[] = Decimal::round2(Decimal::mul($basis, Decimal::div((string) $rule['percent_threshold'], '100')));
        }

        $always = ($rule['absolute_always_threshold'] ?? null) !== null ? (string) $rule['absolute_always_threshold'] : null;
        $all = ($rule['combine'] ?? 'and') !== 'or';
        $ratios = array_map(fn (string $limit): string => $this->ratio($measured, $limit), $limits);
        $breaches = array_map(static fn (string $limit): bool => Decimal::compare($measured, $limit) > 0, $limits);

        $breached = $limits !== [] && ($all ? ! in_array(false, $breaches, true) : in_array(true, $breaches, true));
        $progress = $ratios === [] ? '0' : ($all ? $this->min($ratios) : $this->max($ratios));

        if ($always !== null) {
            $breached = $breached || Decimal::compare($measured, $always) > 0;
            $progress = $this->max([$progress, $this->ratio($measured, $always)]);
        }

        $warning = Decimal::mul((string) ($rule['warning_ratio'] ?? '0.70'), '100');
        $percent = Decimal::mul($progress, '100');
        $status = match (true) {
            $breached => TrafficLight::Red,
            Decimal::compare($percent, $warning) >= 0 => TrafficLight::Yellow,
            default => TrafficLight::Green,
        };

        $limit = $limits === [] ? null : ($all ? $this->max($limits) : $this->min($limits));

        return [
            'code' => $code,
            'label_key' => (string) $rule['label_key'],
            'description_key' => (string) $rule['description_key'],
            'overdue_days' => isset($rule['overdue_days']) ? (int) $rule['overdue_days'] : null,
            'measured' => $measured,
            'limit' => $limit === null ? null : Decimal::round2($limit),
            'progress_percent' => (int) min(200, (int) round((float) $percent)),
            'breached' => $breached,
            'status' => $status->value,
        ];
    }

    private function ratio(string $measured, string $limit): string
    {
        if (! Decimal::isPositive($limit)) {
            return Decimal::isPositive($measured) ? '2' : '0';
        }

        return Decimal::div($measured, $limit);
    }

    /**
     * @param  non-empty-list<string>  $values
     */
    private function min(array $values): string
    {
        $min = $values[0];

        foreach ($values as $value) {
            $min = Decimal::compare($value, $min) < 0 ? $value : $min;
        }

        return $min;
    }

    /**
     * @param  non-empty-list<string>  $values
     */
    private function max(array $values): string
    {
        $max = $values[0];

        foreach ($values as $value) {
            $max = Decimal::compare($value, $max) > 0 ? $value : $max;
        }

        return $max;
    }
}
