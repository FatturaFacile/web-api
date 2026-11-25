<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Exceptions\ExpressionException;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Services\Expression\ExpressionEvaluator;
use Modules\CpqConfigurator\Support\Localized;

/**
 * Deterministic constraint engine. From the raw answers of the sales representative it computes, over the rules of the
 * model and until nothing changes any more, the final values (with the automatic inclusions), the hidden
 * characteristics, the blocked options with the reason, the narrowed numeric ranges, the values derived by decision
 * tables and the list of violations. The same input always gives the same output.
 *
 * Violations of the system itself (`required`, `unknown_option`, `option_blocked`, `out_of_range`, `step_mismatch`,
 * `invalid_value`, `forced_value_blocked`, `rule_error`, `unstable_rules`) carry a code and parameters, the client
 * translates them; violations raised by a rule carry the explanation written in the rule, in the language of the request.
 *
 * @phpstan-import-type Definition from ModelDefinitionLoader
 *
 * @phpstan-type Violation array{code: string, severity: string, characteristic: string|null, rule: string|null, message: string|null, params: array<string, mixed>}
 * @phpstan-type Solution array{
 *     values: array<string, mixed>, visible: array<string, bool>, limits: array<string, array{min: float|null, max: float|null, step: float|null}>,
 *     disabled: array<string, array<string, list<string>>>, auto_included: list<array<string, mixed>>, violations: list<Violation>,
 *     derived: array<string, mixed>, variables: array<string, mixed>, missing: list<string>, valid: bool, complete: bool
 * }
 */
final class ConstraintSolver
{
    private const int MaxPasses = 10;

    public function __construct(private readonly ExpressionEvaluator $evaluator = new ExpressionEvaluator) {}

    /**
     * @param  Definition  $definition
     * @param  array<string, mixed>  $input  characteristic code => raw answer
     * @return Solution
     */
    public function solve(array $definition, array $input, ?string $locale = null): array
    {
        $characteristics = $definition['characteristics'];
        $violations = [];
        $values = $this->normalise($characteristics, $input, $violations);
        $rules = $this->activeRules($definition['rules']);

        $base = $values;
        $state = null;
        $previous = '';
        $stable = false;

        for ($pass = 0; $pass < self::MaxPasses; $pass++) {
            $state = $this->pass($characteristics, $rules, $base, $values, $locale);
            $values = $state['values'];
            $signature = (string) json_encode([$values, array_keys(array_filter($state['hidden'])), $state['derived']]);

            if ($signature === $previous) {
                $stable = true;

                break;
            }

            $previous = $signature;
        }

        /** @var array{values: array<string, mixed>, hidden: array<string, bool>, limits: array<string, array{min: float|null, max: float|null, step: float|null}>, disabled: array<string, array<string, list<string>>>, auto: list<array<string, mixed>>, derived: array<string, mixed>, violations: list<array<string, mixed>>, variables: array<string, mixed>} $state */
        if (! $stable) {
            $violations[] = $this->violation('unstable_rules', 'error', null, null, null);
        }

        $unique = [];

        foreach ([...$violations, ...$state['violations']] as $violation) {
            $unique[(string) json_encode($violation)] = $violation;
        }

        $violations = array_values($unique);
        $missing = [];

        foreach ($characteristics as $code => $characteristic) {
            if ($state['hidden'][$code] ?? false) {
                continue;
            }

            $this->checkCharacteristic($characteristic, $state, $violations, $missing);
        }

        $visible = [];

        foreach ($characteristics as $code => $characteristic) {
            $visible[$code] = ! ($state['hidden'][$code] ?? false);
        }

        $hasError = array_filter($violations, static fn (array $violation): bool => $violation['severity'] === 'error') !== [];

        return [
            'values' => $state['values'],
            'visible' => $visible,
            'limits' => $state['limits'],
            'disabled' => $state['disabled'],
            'auto_included' => $state['auto'],
            'violations' => $violations,
            'derived' => $state['derived'],
            'variables' => $state['variables'],
            'missing' => $missing,
            'valid' => ! $hasError,
            'complete' => ! $hasError && $missing === [],
        ];
    }

    /**
     * Variables of the expressions: the answers (null for a hidden characteristic), `attr.<code>.<property>` of the
     * selected option and the values derived by the decision tables.
     *
     * @param  array<string, array<string, mixed>>  $characteristics
     * @param  array<string, mixed>  $values
     * @param  array<string, bool>  $hidden
     * @param  array<string, mixed>  $derived
     * @return array<string, mixed>
     */
    public function variables(array $characteristics, array $values, array $hidden, array $derived): array
    {
        $variables = $derived;
        $attributes = [];

        foreach ($characteristics as $code => $characteristic) {
            $isHidden = $hidden[$code] ?? false;
            $value = $isHidden ? null : ($values[$code] ?? null);
            $variables[$code] = $value;

            if ($characteristic['type'] === CpqCharacteristic::TypeList && $value !== null) {
                foreach ($characteristic['options'] as $option) {
                    if ($option['code'] === $value) {
                        $attributes[$code] = $option['properties'];
                    }
                }
            }
        }

        $variables['attr'] = $attributes;

        return $variables;
    }

    /**
     * @param  array<string, array<string, mixed>>  $characteristics
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, mixed>
     */
    private function normalise(array $characteristics, array $input, array &$violations): array
    {
        $values = [];

        foreach ($characteristics as $code => $characteristic) {
            $given = array_key_exists($code, $input) ? $input[$code] : null;
            $values[$code] = $this->coerce($characteristic, $given, $violations);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $characteristic
     * @param  list<array<string, mixed>>  $violations
     */
    private function coerce(array $characteristic, mixed $given, array &$violations): mixed
    {
        $code = (string) $characteristic['code'];
        $empty = $given === null || $given === '';

        switch ($characteristic['type']) {
            case CpqCharacteristic::TypeList:
                if ($empty) {
                    foreach ($characteristic['options'] as $option) {
                        if ($option['default'] && $option['active']) {
                            return $option['code'];
                        }
                    }

                    return $characteristic['default'] !== null ? (string) $characteristic['default'] : null;
                }

                return is_scalar($given) ? (string) $given : $this->invalid($code, $violations);

            case CpqCharacteristic::TypeRange:
                if ($empty) {
                    return is_numeric($characteristic['default']) ? (float) $characteristic['default'] : null;
                }

                return is_numeric($given) ? (float) $given : $this->invalid($code, $violations);

            default:
                if ($empty) {
                    return $this->toBool($characteristic['default']);
                }

                return $this->toBool($given);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     */
    private function invalid(string $code, array &$violations): null
    {
        $violations[] = $this->violation('invalid_value', 'error', $code, null, null);

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'y', 's', 'si', 'sì'], true);
        }

        return (bool) $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function activeRules(array $rules): array
    {
        $active = array_values(array_filter($rules, static fn (array $rule): bool => (bool) $rule['active']));
        usort($active, static fn (array $a, array $b): int => [$a['priority'], $a['code']] <=> [$b['priority'], $b['code']]);

        return $active;
    }

    /**
     * One pass over the rules. The conditions read the values of the previous pass; the outcome is always rebuilt from
     * the answers of the user ($base), so a forced value disappears as soon as its condition stops holding.
     *
     * @param  array<string, array<string, mixed>>  $characteristics
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $values  the values of the previous pass
     * @return array<string, mixed>
     */
    private function pass(array $characteristics, array $rules, array $base, array $values, ?string $locale): array
    {
        $hidden = [];
        $limits = [];
        $disabled = [];
        $forced = [];
        $derived = [];
        $violations = [];

        foreach ($characteristics as $code => $characteristic) {
            $limits[$code] = ['min' => $characteristic['min'], 'max' => $characteristic['max'], 'step' => $characteristic['step_size']];
        }

        // The hidden state and the derived values are decided first, from the current answers, because the other rules read them.
        $variables = $this->variables($characteristics, $values, [], []);

        foreach ($rules as $rule) {
            if ($rule['type'] === CpqConstraintRule::Hide && $this->holds($rule, $variables, $violations)) {
                $hidden[(string) $rule['target']] = true;
            }
        }

        $variables = $this->variables($characteristics, $values, $hidden, []);

        foreach ($rules as $rule) {
            if ($rule['type'] !== CpqConstraintRule::DecisionTable || ! $this->holds($rule, $variables, $violations)) {
                continue;
            }

            $this->decide($rule, $characteristics, $variables, $derived, $forced, $violations, $locale);
            $variables = $this->variables($characteristics, $values, $hidden, $derived);
        }

        foreach ($rules as $rule) {
            if (in_array($rule['type'], [CpqConstraintRule::Hide, CpqConstraintRule::DecisionTable], true) || ! $this->holds($rule, $variables, $violations)) {
                continue;
            }

            $message = Localized::message($rule['message'], $variables, $locale);
            $target = (string) ($rule['target'] ?? '');

            switch ($rule['type']) {
                case CpqConstraintRule::ExcludeOptions:
                    foreach ($rule['target_options'] as $option) {
                        $disabled[$target][(string) $option][] = $message;
                    }

                    break;
                case CpqConstraintRule::ForceValue:
                    if ($target !== '' && ! isset($forced[$target])) {
                        $forced[$target] = ['value' => $rule['forced_value'], 'rule' => $rule['code'], 'message' => $message];
                    }

                    break;
                case CpqConstraintRule::LimitRange:
                    $this->narrow($limits, $target, $rule, $variables, $violations);

                    break;
                case CpqConstraintRule::Validate:
                    $violations[] = $this->violation($rule['code'], (string) $rule['severity'], $target !== '' ? $target : null, $rule['code'], $message);

                    break;
            }
        }

        $auto = [];
        $values = $base;

        foreach ($forced as $code => $force) {
            if (! isset($characteristics[$code])) {
                continue;
            }

            $value = $this->castForced($characteristics[$code], $force['value']);
            $user = $base[$code] ?? null;
            $auto[] = [
                'characteristic' => $code,
                'value' => $value,
                'rule' => $force['rule'],
                'message' => $force['message'],
                'overridden' => $user !== null && $user !== false && $user !== $value,
                'previous_value' => $user !== $value ? $user : null,
            ];
            $values[$code] = $value;

            if (isset($disabled[$code][(string) $value]) && is_scalar($value)) {
                $violations[] = $this->violation('forced_value_blocked', 'error', $code, $force['rule'], null, ['value' => $value]);
            }
        }

        // A hidden characteristic carries no answer.
        foreach (array_keys($hidden) as $code) {
            if (isset($values[$code])) {
                $values[$code] = $characteristics[$code]['type'] === CpqCharacteristic::TypeBoolean ? false : null;
            }
        }

        return [
            'values' => $values,
            'hidden' => $hidden,
            'limits' => $limits,
            'disabled' => $disabled,
            'auto' => $auto,
            'derived' => $derived,
            'violations' => $violations,
            'variables' => $this->variables($characteristics, $values, $hidden, $derived),
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $variables
     * @param  list<array<string, mixed>>  $violations
     */
    private function holds(array $rule, array $variables, array &$violations): bool
    {
        try {
            return $this->evaluator->isTrue($rule['condition'], $variables);
        } catch (ExpressionException $exception) {
            $violations[] = $this->violation('rule_error', 'error', null, $rule['code'], $exception->getMessage());

            return false;
        }
    }

    /**
     * First row whose `when` holds publishes its outputs: a characteristic code forces its value, any other name becomes
     * a derived variable of the formulas. An output written `=expression` is evaluated.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, mixed>>  $characteristics
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $derived
     * @param  array<string, array{value: mixed, rule: string, message: string}>  $forced
     * @param  list<array<string, mixed>>  $violations
     */
    private function decide(array $rule, array $characteristics, array $variables, array &$derived, array &$forced, array &$violations, ?string $locale): void
    {
        $matched = null;

        foreach ($rule['params']['rows'] ?? [] as $row) {
            try {
                if ($this->evaluator->isTrue($row['when'] ?? null, $variables)) {
                    $matched = $row;

                    break;
                }
            } catch (ExpressionException $exception) {
                $violations[] = $this->violation('rule_error', 'error', null, $rule['code'], $exception->getMessage());
            }
        }

        $matched ??= isset($rule['params']['default']) ? ['outputs' => $rule['params']['default']] : null;

        if ($matched === null) {
            if ($rule['params']['require_match'] ?? false) {
                $violations[] = $this->violation('no_decision_row', (string) $rule['severity'], null, $rule['code'], Localized::message($rule['message'], $variables, $locale) ?: null);
            }

            return;
        }

        $message = Localized::message($matched['message'] ?? $rule['message'], $variables, $locale);

        foreach ($matched['outputs'] ?? [] as $name => $output) {
            try {
                $value = is_string($output) && str_starts_with($output, '=') ? $this->evaluator->evaluateSource(substr($output, 1), $variables) : $output;
            } catch (ExpressionException $exception) {
                $violations[] = $this->violation('rule_error', 'error', null, $rule['code'], $exception->getMessage());

                continue;
            }

            if (isset($characteristics[$name])) {
                $forced[(string) $name] ??= ['value' => $value, 'rule' => (string) $rule['code'], 'message' => $message];
            } else {
                $derived[(string) $name] = $value;
            }
        }
    }

    /**
     * @param  array<string, array{min: float|null, max: float|null, step: float|null}>  $limits
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $variables
     * @param  list<array<string, mixed>>  $violations
     */
    private function narrow(array &$limits, string $target, array $rule, array $variables, array &$violations): void
    {
        if (! isset($limits[$target])) {
            return;
        }

        foreach (['min', 'max'] as $bound) {
            $raw = $rule['params'][$bound] ?? null;

            if ($raw === null) {
                continue;
            }

            try {
                $number = is_string($raw) && ! is_numeric($raw) ? $this->evaluator->number($raw, $variables) : (float) $raw;
            } catch (ExpressionException $exception) {
                $violations[] = $this->violation('rule_error', 'error', null, $rule['code'], $exception->getMessage());

                continue;
            }

            $current = $limits[$target][$bound];
            $limits[$target][$bound] = $current === null ? $number : ($bound === 'min' ? max($current, $number) : min($current, $number));
        }
    }

    /**
     * @param  array<string, mixed>  $characteristic
     */
    private function castForced(array $characteristic, mixed $value): mixed
    {
        return match ($characteristic['type']) {
            CpqCharacteristic::TypeList => $value === null ? null : (string) $value,
            CpqCharacteristic::TypeRange => is_numeric($value) ? (float) $value : null,
            default => $this->toBool($value),
        };
    }

    /**
     * @param  array<string, mixed>  $characteristic
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $violations
     * @param  list<string>  $missing
     */
    private function checkCharacteristic(array $characteristic, array $state, array &$violations, array &$missing): void
    {
        $code = (string) $characteristic['code'];
        $value = $state['values'][$code] ?? null;
        $label = Localized::pick($characteristic['label']);

        if ($value === null) {
            if ($characteristic['required'] && $characteristic['type'] !== CpqCharacteristic::TypeBoolean) {
                $missing[] = $code;
            }

            return;
        }

        if ($characteristic['type'] === CpqCharacteristic::TypeList) {
            $option = null;

            foreach ($characteristic['options'] as $candidate) {
                if ($candidate['code'] === $value) {
                    $option = $candidate;
                }
            }

            if ($option === null || ! $option['active']) {
                $violations[] = $this->violation('unknown_option', 'error', $code, null, null, ['value' => (string) $value, 'label' => $label]);

                return;
            }

            if (isset($state['disabled'][$code][$value])) {
                $violations[] = $this->violation('option_blocked', 'error', $code, null, $state['disabled'][$code][$value][0] ?: null, ['value' => (string) $value, 'label' => $label]);
            }

            return;
        }

        if ($characteristic['type'] !== CpqCharacteristic::TypeRange) {
            return;
        }

        $limit = $state['limits'][$code];
        $number = (float) $value;

        if (($limit['min'] !== null && $number < $limit['min'] - 1e-9) || ($limit['max'] !== null && $number > $limit['max'] + 1e-9)) {
            $violations[] = $this->violation('out_of_range', 'error', $code, null, null, ['value' => $number, 'min' => $limit['min'], 'max' => $limit['max'], 'label' => $label]);

            return;
        }

        if ($limit['step'] !== null && $limit['step'] > 0) {
            $steps = ($number - ($limit['min'] ?? 0.0)) / $limit['step'];

            if (abs($steps - round($steps)) > 1e-6) {
                $violations[] = $this->violation('step_mismatch', 'error', $code, null, null, ['value' => $number, 'step' => $limit['step'], 'label' => $label]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{code: string, severity: string, characteristic: string|null, rule: string|null, message: string|null, params: array<string, mixed>}
     */
    private function violation(string $code, string $severity, ?string $characteristic, ?string $rule, ?string $message, array $params = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'characteristic' => $characteristic, 'rule' => $rule, 'message' => $message, 'params' => $params];
    }
}
