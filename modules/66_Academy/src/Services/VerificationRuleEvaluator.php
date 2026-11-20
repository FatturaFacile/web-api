<?php

declare(strict_types=1);

namespace Modules\Academy\Services;

use Illuminate\Support\Arr;
use JsonException;
use Modules\Academy\Domain\ValueObjects\VerificationRuleSpec;

/**
 * Deterministic, side-effect free evaluator of the mission predicates. It parses the small grammar documented on
 * VerificationRuleSpec; nothing is ever passed to eval() or to a dynamic call, and a clause it cannot parse is false.
 */
final class VerificationRuleEvaluator
{
    private const string Operators = '==|!=|>=|<=|>|<|not_in|in|contains';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function matches(VerificationRuleSpec $rule, string $eventName, array $payload): bool
    {
        return $rule->targetEvent === $eventName && $this->evaluate($rule->predicate, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function evaluate(string $predicate, array $payload): bool
    {
        $predicate = trim($predicate);

        if ($predicate === '') {
            return true;
        }

        foreach (preg_split('/\s+OR\s+/', $predicate) ?: [] as $conjunction) {
            $all = true;

            foreach (preg_split('/\s+AND\s+/', $conjunction) ?: [] as $clause) {
                if (! $this->clause(trim($clause), $payload)) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function clause(string $clause, array $payload): bool
    {
        if (preg_match('/^([A-Za-z0-9_.\-]+)\s+exists$/', $clause, $m) === 1) {
            return Arr::has($payload, $m[1]) && Arr::get($payload, $m[1]) !== null;
        }

        if (preg_match('/^([A-Za-z0-9_.\-]+)\s*('.self::Operators.')\s*(.+)$/s', $clause, $m) !== 1) {
            return false;
        }

        [, $path, $operator, $rawLiteral] = $m;

        try {
            $expected = json_decode(trim($rawLiteral), true, 8, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return false;
        }

        return $this->compare(Arr::get($payload, $path), $operator, $expected);
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '==' => $this->equals($actual, $expected),
            '!=' => ! $this->equals($actual, $expected),
            '>' => $this->numeric($actual, $expected) && (float) $actual > (float) $expected,
            '>=' => $this->numeric($actual, $expected) && (float) $actual >= (float) $expected,
            '<' => $this->numeric($actual, $expected) && (float) $actual < (float) $expected,
            '<=' => $this->numeric($actual, $expected) && (float) $actual <= (float) $expected,
            'in' => is_array($expected) && $this->inList($actual, $expected),
            'not_in' => is_array($expected) && ! $this->inList($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            default => false,
        };
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if ($this->numeric($actual, $expected)) {
            return (float) $actual === (float) $expected;
        }

        return is_scalar($actual) && is_scalar($expected) ? (string) $actual === (string) $expected && is_bool($actual) === is_bool($expected) : $actual === $expected;
    }

    private function numeric(mixed $actual, mixed $expected): bool
    {
        return (is_int($actual) || is_float($actual) || (is_string($actual) && is_numeric($actual)))
            && (is_int($expected) || is_float($expected));
    }

    /**
     * @param  array<mixed>  $list
     */
    private function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $candidate) {
            if ($this->equals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return $this->inList($expected, $actual);
        }

        return is_string($actual) && is_string($expected) && $expected !== '' && str_contains($actual, $expected);
    }
}
