<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Exceptions\ExpressionException;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Services\Expression\ExpressionParser;

/**
 * Static check of a model before it is published: every expression parses and reads only known variables, every rule
 * points at characteristics and options that exist, every list has a selectable option, every pricing effect is known.
 * Issues carry a code and parameters (the client translates them).
 *
 * @phpstan-import-type Definition from ModelDefinitionLoader
 */
final class ModelDiagnostics
{
    /** @var list<string> */
    private const array BuiltInVariables = ['quantity', 'bom_cost', 'routing_cost', 'cost_subtotal', 'list_subtotal', 'attr'];

    /**
     * @param  Definition  $definition
     * @return list<array{severity: string, code: string, scope: string, params: array<string, mixed>}>
     */
    public function check(array $definition): array
    {
        $issues = [];
        $characteristics = $definition['characteristics'];
        $known = [...array_keys($characteristics), ...self::BuiltInVariables, ...$this->derivedNames($definition)];

        if ($characteristics === []) {
            $issues[] = $this->issue('error', 'no_characteristics', 'model');
        }

        foreach ($characteristics as $code => $characteristic) {
            if ($characteristic['type'] === CpqCharacteristic::TypeList && array_filter($characteristic['options'], static fn (array $option): bool => $option['active']) === []) {
                $issues[] = $this->issue('error', 'list_without_options', "characteristic:{$code}");
            }

            if ($characteristic['type'] === CpqCharacteristic::TypeRange && $characteristic['min'] !== null && $characteristic['max'] !== null && $characteristic['min'] > $characteristic['max']) {
                $issues[] = $this->issue('error', 'range_inverted', "characteristic:{$code}");
            }
        }

        foreach ($definition['rules'] as $rule) {
            $scope = "rule:{$rule['code']}";
            $this->expression($rule['condition'], $known, $characteristics, $scope, $issues);
            $this->rule($rule, $characteristics, $known, $scope, $issues);
        }

        foreach ($definition['bom'] as $mapping) {
            $scope = "bom:{$mapping['code']}";
            $this->expression($mapping['condition'], $known, $characteristics, $scope, $issues);
            $this->expression($mapping['quantity_formula'], $known, $characteristics, $scope, $issues);
            $this->expression($mapping['unit_cost_formula'], $known, $characteristics, $scope, $issues);
        }

        foreach ($definition['routing'] as $mapping) {
            $scope = "routing:{$mapping['code']}";
            $this->expression($mapping['condition'], $known, $characteristics, $scope, $issues);
            $this->expression($mapping['setup_hours_formula'], $known, $characteristics, $scope, $issues);
            $this->expression($mapping['run_hours_formula'], $known, $characteristics, $scope, $issues);
        }

        foreach ($definition['pricing'] as $formula) {
            $scope = "pricing:{$formula['code']}";

            if (! in_array($formula['effect'], CpqPricingFormula::Effects, true)) {
                $issues[] = $this->issue('error', 'unknown_effect', $scope, ['effect' => $formula['effect']]);
            }

            $this->expression($formula['condition'], $known, $characteristics, $scope, $issues);
            $this->expression($formula['formula'], $known, $characteristics, $scope, $issues);
        }

        return $issues;
    }

    /**
     * Parses one expression and says which of its variables are unknown (a mistyped characteristic code).
     *
     * @param  list<string>  $known
     * @param  array<string, array<string, mixed>>  $characteristics
     * @return array{valid: bool, error: string|null, variables: list<string>, unknown: list<string>}
     */
    public function inspect(?string $source, array $known, array $characteristics): array
    {
        if ($source === null || trim($source) === '') {
            return ['valid' => true, 'error' => null, 'variables' => [], 'unknown' => []];
        }

        try {
            $variables = ExpressionParser::parse($source)->variables();
        } catch (ExpressionException $exception) {
            return ['valid' => false, 'error' => $exception->getMessage(), 'variables' => [], 'unknown' => []];
        }

        $unknown = [];

        foreach ($variables as $name) {
            $root = explode('.', $name)[0];

            if (! in_array($root, $known, true)) {
                $unknown[] = $name;

                continue;
            }

            if ($root === 'attr' && count($parts = explode('.', $name)) > 1 && ! isset($characteristics[$parts[1]])) {
                $unknown[] = $name;
            }
        }

        return ['valid' => true, 'error' => null, 'variables' => $variables, 'unknown' => $unknown];
    }

    /**
     * The names a model makes available to its expressions besides the characteristics.
     *
     * @param  Definition  $definition
     * @return list<string>
     */
    public function derivedNames(array $definition): array
    {
        $names = [];

        foreach ($definition['rules'] as $rule) {
            if ($rule['type'] !== CpqConstraintRule::DecisionTable) {
                continue;
            }

            $outputs = [$rule['params']['default'] ?? []];

            foreach ($rule['params']['rows'] ?? [] as $row) {
                $outputs[] = $row['outputs'] ?? [];
            }

            foreach ($outputs as $map) {
                foreach (array_keys((array) $map) as $name) {
                    $names[(string) $name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param  list<string>  $known
     * @param  array<string, array<string, mixed>>  $characteristics
     * @param  list<array<string, mixed>>  $issues
     */
    private function expression(?string $source, array $known, array $characteristics, string $scope, array &$issues): void
    {
        $result = $this->inspect($source, $known, $characteristics);

        if (! $result['valid']) {
            $issues[] = $this->issue('error', 'syntax_error', $scope, ['detail' => $result['error']]);
        }

        foreach ($result['unknown'] as $name) {
            $issues[] = $this->issue('error', 'unknown_variable', $scope, ['variable' => $name]);
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, mixed>>  $characteristics
     * @param  list<string>  $known
     * @param  list<array<string, mixed>>  $issues
     */
    private function rule(array $rule, array $characteristics, array $known, string $scope, array &$issues): void
    {
        if (! in_array($rule['type'], CpqConstraintRule::Types, true)) {
            $issues[] = $this->issue('error', 'unknown_rule_type', $scope, ['type' => $rule['type']]);

            return;
        }

        if ($rule['type'] === CpqConstraintRule::DecisionTable) {
            foreach ($rule['params']['rows'] ?? [] as $index => $row) {
                $this->expression($row['when'] ?? null, $known, $characteristics, "{$scope}:row{$index}", $issues);
            }

            return;
        }

        if ($rule['type'] === CpqConstraintRule::Validate) {
            return;
        }

        $target = $rule['target'];

        if ($target === null || ! isset($characteristics[$target])) {
            $issues[] = $this->issue('error', 'unknown_target', $scope, ['characteristic' => (string) $target]);

            return;
        }

        $codes = array_column($characteristics[$target]['options'], 'code');

        foreach ($rule['type'] === CpqConstraintRule::ExcludeOptions ? $rule['target_options'] : [] as $option) {
            if (! in_array($option, $codes, true)) {
                $issues[] = $this->issue('error', 'unknown_option', $scope, ['characteristic' => $target, 'option' => (string) $option]);
            }
        }

        if ($rule['type'] === CpqConstraintRule::ForceValue && $characteristics[$target]['type'] === CpqCharacteristic::TypeList && ! in_array($rule['forced_value'], $codes, true)) {
            $issues[] = $this->issue('error', 'unknown_option', $scope, ['characteristic' => $target, 'option' => (string) json_encode($rule['forced_value'])]);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{severity: string, code: string, scope: string, params: array<string, mixed>}
     */
    private function issue(string $severity, string $code, string $scope, array $params = []): array
    {
        return ['severity' => $severity, 'code' => $code, 'scope' => $scope, 'params' => $params];
    }
}
