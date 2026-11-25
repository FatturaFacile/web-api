<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services\Expression;

use Modules\CpqConfigurator\Exceptions\ExpressionException;

/**
 * Walks the syntax tree of an Expression over a set of variables. Deterministic and side-effect free: `and`, `or` and
 * `if` evaluate only the branch they need, a missing variable reads as null, and arithmetic on a value that is not a
 * number, a division by zero or a result that is not finite raise an ExpressionException.
 */
final class ExpressionEvaluator
{
    /** @var list<string> */
    public const array Functions = ['min', 'max', 'round', 'ceil', 'floor', 'abs', 'sqrt', 'pow', 'if', 'coalesce'];

    /** @var array<string, array{0: int, 1: int}> */
    private const array Arity = [
        'min' => [1, 50], 'max' => [1, 50], 'round' => [1, 2], 'ceil' => [1, 1], 'floor' => [1, 1], 'abs' => [1, 1],
        'sqrt' => [1, 1], 'pow' => [2, 2], 'if' => [3, 3], 'coalesce' => [1, 50],
    ];

    public static function assertArity(string $function, int $count): void
    {
        [$min, $max] = self::Arity[$function];

        if ($count < $min || $count > $max) {
            throw new ExpressionException("The function {$function}() takes ".($min === $max ? (string) $min : "{$min} to {$max}")." argument(s), {$count} given.");
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function evaluate(Expression $expression, array $variables): mixed
    {
        return $this->visit($expression->root, $variables);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function evaluateSource(string $source, array $variables): mixed
    {
        return $this->evaluate(ExpressionParser::parse($source), $variables);
    }

    /**
     * A condition: an empty or missing source is always true.
     *
     * @param  array<string, mixed>  $variables
     */
    public function isTrue(?string $source, array $variables): bool
    {
        if ($source === null || trim($source) === '') {
            return true;
        }

        return $this->truthy($this->evaluateSource($source, $variables));
    }

    /**
     * A numeric formula, rounded to 4 decimals.
     *
     * @param  array<string, mixed>  $variables
     */
    public function number(?string $source, array $variables, float $default = 0.0): float
    {
        if ($source === null || trim($source) === '') {
            return $default;
        }

        return round($this->toNumber($this->evaluateSource($source, $variables)), 4);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $vars
     */
    private function visit(array $node, array $vars): mixed
    {
        return match ($node['t']) {
            'num', 'str', 'bool' => $node['v'],
            'null' => null,
            'var' => $this->variable((string) $node['n'], $vars),
            'list' => array_map(fn (array $item): mixed => $this->visit($item, $vars), $node['items']),
            'un' => $this->unary((string) $node['op'], $node['e'], $vars),
            'bin' => $this->binary((string) $node['op'], $node['l'], $node['r'], $vars),
            'call' => $this->call((string) $node['fn'], $node['args'], $vars),
            default => throw new ExpressionException('Unknown expression node.'),
        };
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function variable(string $name, array $vars): mixed
    {
        if (array_key_exists($name, $vars)) {
            return $vars[$name];
        }

        $value = $vars;

        foreach (explode('.', $name) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $operand
     * @param  array<string, mixed>  $vars
     */
    private function unary(string $operator, array $operand, array $vars): mixed
    {
        $value = $this->visit($operand, $vars);

        return $operator === 'not' ? ! $this->truthy($value) : -$this->toNumber($value);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<string, mixed>  $vars
     */
    private function binary(string $operator, array $left, array $right, array $vars): mixed
    {
        if ($operator === 'and') {
            return $this->truthy($this->visit($left, $vars)) && $this->truthy($this->visit($right, $vars));
        }

        if ($operator === 'or') {
            return $this->truthy($this->visit($left, $vars)) || $this->truthy($this->visit($right, $vars));
        }

        $a = $this->visit($left, $vars);
        $b = $this->visit($right, $vars);

        return match ($operator) {
            '==' => $this->equals($a, $b),
            '!=' => ! $this->equals($a, $b),
            '<' => $a !== null && $b !== null && $this->compare($a, $b) < 0,
            '<=' => $a !== null && $b !== null && $this->compare($a, $b) <= 0,
            '>' => $a !== null && $b !== null && $this->compare($a, $b) > 0,
            '>=' => $a !== null && $b !== null && $this->compare($a, $b) >= 0,
            'in' => $this->contains($b, $a),
            '+' => $this->finite($this->toNumber($a) + $this->toNumber($b)),
            '-' => $this->finite($this->toNumber($a) - $this->toNumber($b)),
            '*' => $this->finite($this->toNumber($a) * $this->toNumber($b)),
            '/' => $this->divide($this->toNumber($a), $this->toNumber($b)),
            '%' => $this->modulo($this->toNumber($a), $this->toNumber($b)),
            '^' => $this->power($this->toNumber($a), $this->toNumber($b)),
            default => throw new ExpressionException("Unknown operator \"{$operator}\"."),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @param  array<string, mixed>  $vars
     */
    private function call(string $function, array $args, array $vars): mixed
    {
        if ($function === 'if') {
            return $this->truthy($this->visit($args[0], $vars)) ? $this->visit($args[1], $vars) : $this->visit($args[2], $vars);
        }

        $values = array_map(fn (array $arg): mixed => $this->visit($arg, $vars), $args);

        if ($function === 'coalesce') {
            foreach ($values as $value) {
                if ($value !== null) {
                    return $value;
                }
            }

            return null;
        }

        if (in_array($function, ['min', 'max'], true)) {
            $numbers = [];

            foreach ($values as $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    $numbers[] = $this->toNumber($item);
                }
            }

            if ($numbers === []) {
                throw new ExpressionException("The function {$function}() needs at least one number.");
            }

            return $function === 'min' ? min($numbers) : max($numbers);
        }

        $first = $this->toNumber($values[0]);

        return match ($function) {
            'round' => round($first, isset($values[1]) ? (int) $this->toNumber($values[1]) : 0),
            'ceil' => ceil($first - 1e-9),
            'floor' => floor($first + 1e-9),
            'abs' => abs($first),
            'sqrt' => $first < 0 ? throw new ExpressionException('The square root of a negative number is undefined.') : sqrt($first),
            'pow' => $this->power($first, $this->toNumber($values[1])),
            default => throw new ExpressionException("Unknown function \"{$function}\"."),
        };
    }

    private function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return $value !== '' && strtolower($value) !== 'false' && $value !== '0';
        }

        return (bool) $value;
    }

    private function toNumber(mixed $value): float
    {
        return match (true) {
            $value === null => 0.0,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => throw new ExpressionException('A value that is not a number is used in an arithmetic expression.'),
        };
    }

    private function equals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        if (is_bool($a) || is_bool($b)) {
            return $this->truthy($a) === $this->truthy($b);
        }

        if (is_array($a) || is_array($b)) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return (string) $a === (string) $b;
    }

    private function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return 0;
        }

        if (is_numeric($a) && is_numeric($b)) {
            $difference = (float) $a - (float) $b;

            return abs($difference) < 1e-9 ? 0 : ($difference < 0 ? -1 : 1);
        }

        if (is_array($a) || is_array($b)) {
            throw new ExpressionException('A list cannot be compared with < or >.');
        }

        return strcmp((string) $a, (string) $b) <=> 0;
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if (! is_array($haystack)) {
            return false;
        }

        foreach ($haystack as $item) {
            if ($this->equals($needle, $item)) {
                return true;
            }
        }

        return false;
    }

    private function divide(float $a, float $b): float
    {
        if (abs($b) < 1e-12) {
            throw new ExpressionException('Division by zero.');
        }

        return $this->finite($a / $b);
    }

    private function modulo(float $a, float $b): float
    {
        if (abs($b) < 1e-12) {
            throw new ExpressionException('Division by zero.');
        }

        return $this->finite(fmod($a, $b));
    }

    private function power(float $base, float $exponent): float
    {
        return $this->finite($base ** $exponent);
    }

    private function finite(float $value): float
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new ExpressionException('The result of the expression is not a finite number.');
        }

        return $value;
    }
}
