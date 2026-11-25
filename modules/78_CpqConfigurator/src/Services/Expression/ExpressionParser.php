<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services\Expression;

use Modules\CpqConfigurator\Exceptions\ExpressionException;

/**
 * Recursive-descent parser of the rule and formula language. Nothing is ever passed to eval(): the source becomes a
 * syntax tree that ExpressionEvaluator walks.
 *
 * Grammar, from the lowest to the highest precedence: OR (`or`, `||`), AND (`and`, `&&`), NOT (`not`, `!`),
 * comparison (`==`, `!=`, `<`, `<=`, `>`, `>=`, `in`, `not in`), `+ -`, `* / %`, unary minus, `^` (right associative).
 * Primaries: numbers, 'strings', true, false, null, variables (`power_kw`, `attr.material.density`), lists `[1, 2]`,
 * parentheses and calls (`min`, `max`, `round`, `ceil`, `floor`, `abs`, `sqrt`, `pow`, `if`, `coalesce`).
 */
final class ExpressionParser
{
    public const int MaxLength = 2000;

    private const int MaxDepth = 40;

    /** @var array<string, Expression> */
    private static array $cache = [];

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];

    private int $position = 0;

    private int $depth = 0;

    public static function parse(string $source): Expression
    {
        return self::$cache[$source] ??= (new self)->run($source);
    }

    private function run(string $source): Expression
    {
        if (trim($source) === '') {
            throw new ExpressionException('The expression is empty.');
        }

        if (strlen($source) > self::MaxLength) {
            throw new ExpressionException('The expression is longer than '.self::MaxLength.' characters.');
        }

        $this->tokens = $this->tokenize($source);
        $this->position = 0;
        $this->depth = 0;
        $root = $this->parseOr();

        if ($this->peek()['type'] !== 'end') {
            throw new ExpressionException("Unexpected \"{$this->peek()['value']}\" in the expression.");
        }

        return new Expression($source, $root);
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?/', $source, $match, 0, $i);
                $tokens[] = ['type' => 'num', 'value' => $match[0]];
                $i += strlen($match[0]);

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*/', $source, $match, 0, $i);
                $word = $match[0];
                $keyword = strtolower($word);
                $tokens[] = in_array($keyword, ['and', 'or', 'not', 'in', 'true', 'false', 'null'], true)
                    ? ['type' => 'kw', 'value' => $keyword]
                    : ['type' => 'id', 'value' => $word];
                $i += strlen($word);

                continue;
            }

            if ($char === "'" || $char === '"') {
                $value = '';
                $i++;

                while ($i < $length && $source[$i] !== $char) {
                    if ($source[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }

                    $value .= $source[$i];
                    $i++;
                }

                if ($i >= $length) {
                    throw new ExpressionException('A string is not closed.');
                }

                $tokens[] = ['type' => 'str', 'value' => $value];
                $i++;

                continue;
            }

            $two = substr($source, $i, 2);

            if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;

                continue;
            }

            if (str_contains('+-*/%^<>()[],!', $char)) {
                $tokens[] = ['type' => 'op', 'value' => $char];
                $i++;

                continue;
            }

            throw new ExpressionException("Unexpected character \"{$char}\" in the expression.");
        }

        $tokens[] = ['type' => 'end', 'value' => 'the end of the expression'];

        return $tokens;
    }

    /**
     * @return array{type: string, value: string}
     */
    private function peek(): array
    {
        return $this->tokens[$this->position];
    }

    /**
     * @return array{type: string, value: string}
     */
    private function next(): array
    {
        return $this->tokens[$this->position++];
    }

    private function isOp(string ...$values): bool
    {
        $token = $this->peek();

        return $token['type'] === 'op' && in_array($token['value'], $values, true);
    }

    private function isKeyword(string $value): bool
    {
        $token = $this->peek();

        return $token['type'] === 'kw' && $token['value'] === $value;
    }

    private function expectOp(string $value): void
    {
        if (! $this->isOp($value)) {
            throw new ExpressionException("Expected \"{$value}\" but found \"{$this->peek()['value']}\".");
        }

        $this->position++;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOr(): array
    {
        $this->enter();
        $left = $this->parseAnd();

        while ($this->isKeyword('or') || $this->isOp('||')) {
            $this->position++;
            $left = ['t' => 'bin', 'op' => 'or', 'l' => $left, 'r' => $this->parseAnd()];
        }

        $this->depth--;

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAnd(): array
    {
        $left = $this->parseNot();

        while ($this->isKeyword('and') || $this->isOp('&&')) {
            $this->position++;
            $left = ['t' => 'bin', 'op' => 'and', 'l' => $left, 'r' => $this->parseNot()];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseNot(): array
    {
        if ($this->isKeyword('not') || $this->isOp('!')) {
            $this->position++;
            $this->enter();
            $operand = $this->parseNot();
            $this->depth--;

            return ['t' => 'un', 'op' => 'not', 'e' => $operand];
        }

        return $this->parseComparison();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(): array
    {
        $left = $this->parseAdditive();

        while (true) {
            if ($this->isOp('==', '!=', '<', '<=', '>', '>=')) {
                $operator = $this->next()['value'];
                $left = ['t' => 'bin', 'op' => $operator, 'l' => $left, 'r' => $this->parseAdditive()];

                continue;
            }

            if ($this->isKeyword('in')) {
                $this->position++;
                $left = ['t' => 'bin', 'op' => 'in', 'l' => $left, 'r' => $this->parseAdditive()];

                continue;
            }

            if ($this->isKeyword('not') && ($this->tokens[$this->position + 1]['type'] ?? '') === 'kw' && $this->tokens[$this->position + 1]['value'] === 'in') {
                $this->position += 2;
                $left = ['t' => 'un', 'op' => 'not', 'e' => ['t' => 'bin', 'op' => 'in', 'l' => $left, 'r' => $this->parseAdditive()]];

                continue;
            }

            return $left;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAdditive(): array
    {
        $left = $this->parseMultiplicative();

        while ($this->isOp('+', '-')) {
            $operator = $this->next()['value'];
            $left = ['t' => 'bin', 'op' => $operator, 'l' => $left, 'r' => $this->parseMultiplicative()];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMultiplicative(): array
    {
        $left = $this->parseUnary();

        while ($this->isOp('*', '/', '%')) {
            $operator = $this->next()['value'];
            $left = ['t' => 'bin', 'op' => $operator, 'l' => $left, 'r' => $this->parseUnary()];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUnary(): array
    {
        if ($this->isOp('-', '+')) {
            $operator = $this->next()['value'];
            $this->enter();
            $operand = $this->parseUnary();
            $this->depth--;

            return $operator === '-' ? ['t' => 'un', 'op' => 'neg', 'e' => $operand] : $operand;
        }

        return $this->parsePower();
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePower(): array
    {
        $base = $this->parsePrimary();

        if ($this->isOp('^')) {
            $this->position++;
            $this->enter();
            $exponent = $this->parseUnary();
            $this->depth--;

            return ['t' => 'bin', 'op' => '^', 'l' => $base, 'r' => $exponent];
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePrimary(): array
    {
        $token = $this->next();

        switch ($token['type']) {
            case 'num':
                return ['t' => 'num', 'v' => (float) $token['value']];
            case 'str':
                return ['t' => 'str', 'v' => $token['value']];
            case 'kw':
                return match ($token['value']) {
                    'true' => ['t' => 'bool', 'v' => true],
                    'false' => ['t' => 'bool', 'v' => false],
                    'null' => ['t' => 'null'],
                    default => throw new ExpressionException("Unexpected \"{$token['value']}\" in the expression."),
                };
            case 'id':
                if ($this->isOp('(')) {
                    return $this->parseCall($token['value']);
                }

                return ['t' => 'var', 'n' => $token['value']];
            case 'op':
                if ($token['value'] === '(') {
                    $this->enter();
                    $inner = $this->parseOr();
                    $this->depth--;
                    $this->expectOp(')');

                    return $inner;
                }

                if ($token['value'] === '[') {
                    $items = [];

                    if (! $this->isOp(']')) {
                        do {
                            $items[] = $this->parseOr();
                        } while ($this->consumeComma());
                    }

                    $this->expectOp(']');

                    return ['t' => 'list', 'items' => $items];
                }
        }

        throw new ExpressionException("Unexpected \"{$token['value']}\" in the expression.");
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCall(string $name): array
    {
        $function = strtolower($name);

        if (! in_array($function, ExpressionEvaluator::Functions, true)) {
            throw new ExpressionException("Unknown function \"{$name}\".");
        }

        $this->expectOp('(');
        $args = [];

        if (! $this->isOp(')')) {
            do {
                $args[] = $this->parseOr();
            } while ($this->consumeComma());
        }

        $this->expectOp(')');
        ExpressionEvaluator::assertArity($function, count($args));

        return ['t' => 'call', 'fn' => $function, 'args' => $args];
    }

    private function consumeComma(): bool
    {
        if ($this->isOp(',')) {
            $this->position++;

            return true;
        }

        return false;
    }

    private function enter(): void
    {
        if (++$this->depth > self::MaxDepth) {
            throw new ExpressionException('The expression is nested too deeply.');
        }
    }
}
