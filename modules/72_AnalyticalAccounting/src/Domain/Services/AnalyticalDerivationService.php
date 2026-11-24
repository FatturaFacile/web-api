<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Services;

/**
 * Resolves the analytical dimensions of a posting line. Dimensions given explicitly always win (MANUAL); the remaining
 * ones come from the matching rules in priority order (lowest number first, then the most specific rule, then the
 * earliest id), the first rule that defines a dimension supplying it; what is still empty falls back to the defaults of
 * the resolved center (MASTER_DEFAULT). Every dimension carries its provenance.
 */
final class AnalyticalDerivationService
{
    public const array Dimensions = ['cost_center_id', 'profit_center_id', 'project_id', 'product_line_id', 'cost_element_type'];

    public const array Criteria = ['accounting_cause_id', 'journal_type', 'product_id', 'product_category_id', 'account_id', 'counterparty_type', 'counterparty_id'];

    /**
     * @param  list<array<string, mixed>>  $rules  rule rows with id, priority, is_active, valid_from, valid_to, criteria and targets
     * @param  array<string, mixed>  $context  criteria values of the posting plus "date" (Y-m-d)
     * @param  array<string, string|null>  $explicit  dimensions chosen by the user
     * @param  array<string, array<string, string|null>>  $centerDefaults  master defaults by center id (default_profit_center_id, default_project_id, default_product_line_id, default_cost_element_type)
     * @return array{dimensions: array<string, string|null>, provenance: array<string, string>, source: string, rule_ids: list<string>}
     */
    public function resolve(array $rules, array $context, array $explicit = [], array $centerDefaults = []): array
    {
        $dimensions = array_fill_keys(self::Dimensions, null);
        $provenance = [];
        foreach (self::Dimensions as $dimension) {
            if (($explicit[$dimension] ?? null) !== null && $explicit[$dimension] !== '') {
                $dimensions[$dimension] = $explicit[$dimension];
                $provenance[$dimension] = 'MANUAL';
            }
        }

        $usedRules = [];
        foreach ($this->matching($rules, $context) as $rule) {
            foreach (self::Dimensions as $dimension) {
                if ($dimensions[$dimension] === null && ($rule[$dimension] ?? null) !== null) {
                    $dimensions[$dimension] = (string) $rule[$dimension];
                    $provenance[$dimension] = 'RULE';
                    $usedRules[(string) $rule['id']] = true;
                }
            }
        }

        $defaults = $centerDefaults[(string) $dimensions['cost_center_id']] ?? [];
        $map = ['profit_center_id' => 'default_profit_center_id', 'project_id' => 'default_project_id', 'product_line_id' => 'default_product_line_id', 'cost_element_type' => 'default_cost_element_type'];
        foreach ($map as $dimension => $column) {
            if ($dimensions[$dimension] === null && ($defaults[$column] ?? null) !== null) {
                $dimensions[$dimension] = (string) $defaults[$column];
                $provenance[$dimension] = 'MASTER_DEFAULT';
            }
        }

        $source = in_array('MANUAL', $provenance, true) ? 'MANUAL' : (in_array('RULE', $provenance, true) ? 'RULE' : (in_array('MASTER_DEFAULT', $provenance, true) ? 'MASTER_DEFAULT' : 'MANUAL'));

        return ['dimensions' => $dimensions, 'provenance' => $provenance, 'source' => $source, 'rule_ids' => array_keys($usedRules)];
    }

    /**
     * The rules that apply to the context, best first.
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function matching(array $rules, array $context): array
    {
        $date = (string) ($context['date'] ?? date('Y-m-d'));
        $matched = [];
        foreach ($rules as $rule) {
            if (! ($rule['is_active'] ?? true)) {
                continue;
            }
            if (($rule['valid_from'] ?? null) !== null && $date < (string) $rule['valid_from']) {
                continue;
            }
            if (($rule['valid_to'] ?? null) !== null && $date > (string) $rule['valid_to']) {
                continue;
            }
            $specificity = 0;
            $ok = true;
            foreach (self::Criteria as $criterion) {
                $expected = $rule[$criterion] ?? null;
                if ($expected === null) {
                    continue;
                }
                if (($context[$criterion] ?? null) !== $expected) {
                    $ok = false;
                    break;
                }
                $specificity++;
            }
            if ($ok) {
                $matched[] = $rule + ['_specificity' => $specificity];
            }
        }

        usort($matched, static fn (array $a, array $b): int => [(int) ($a['priority'] ?? 100), -$a['_specificity'], (string) $a['id']] <=> [(int) ($b['priority'] ?? 100), -$b['_specificity'], (string) $b['id']]);

        return $matched;
    }

    /**
     * Splits a line amount over allocation vectors: each vector has a "percentage" or an "amount"; percentages must sum
     * to 100 and amounts to the line amount. Percentages use the Largest Remainder method on cents.
     *
     * @param  list<array<string, mixed>>  $vectors
     * @return list<array<string, mixed>> the vectors with "amount_cents" and "split_percentage"
     */
    public function splitVectors(int $amountCents, array $vectors): array
    {
        return (new AllocationVectorSplitter)->split($amountCents, $vectors);
    }
}
