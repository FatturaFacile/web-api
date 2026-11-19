<?php

declare(strict_types=1);

namespace Modules\Analytics\Calculation;

use InvalidArgumentException;

/**
 * Strict whitelist parser of the formula of a calculated field: decimal numbers, references to measure keys, the four
 * operations, unary minus, parentheses and the functions ABS, ROUND, COALESCE and NULLIF. Nothing else is accepted, and
 * the result is an abstract syntax tree evaluated in PHP over the already aggregated rows, so no part of a formula ever
 * reaches the database.
 *
 * Nodes: `['num', string]`, `['ref', key]`, `['neg', node]`, `['bin', op, left, right]`, `['fn', NAME, list<node>]`.
 */
final class FormulaParser
{
    public const int MaxLength = 500;

    public const int MaxTokens = 150;

    public const int MaxDepth = 20;

    /**
     * Allowed functions and their argument count range.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const array Functions = ['ABS' => [1, 1], 'ROUND' => [1, 2], 'COALESCE' => [2, 8], 'NULLIF' => [2, 2]];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $tokens = [];

    private int $position = 0;

    /**
     * The syntax tree of a formula.
     *
     * @return array<int, mixed>
     *
     * @throws InvalidArgumentException when the formula is not valid
     */
    public function parse(string $formula): array
    {
        $this->tokens = $this->tokenize($formula);
        $this->position = 0;
        $tree = $this->expression(0);

        if ($this->position !== count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected token "'.$this->tokens[$this->position][1].'".');
        }

        return $tree;
    }

    /**
     * The measure keys a formula reads.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the formula is not valid
     */
    public function references(string $formula): array
    {
        return array_values(array_unique($this->collect($this->parse($formula))));
    }

    /**
     * @param  array<int, mixed>  $node
     * @return list<string>
     */
    private function collect(array $node): array
    {
        return match ($node[0]) {
            'ref' => [$node[1]],
            'neg' => $this->collect($node[1]),
            'bin' => [...$this->collect($node[2]), ...$this->collect($node[3])],
            'fn' => array_merge([], ...array_map(fn (array $argument): array => $this->collect($argument), $node[2])),
            default => [],
        };
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function tokenize(string $formula): array
    {
        if ($formula === '' || strlen($formula) > self::MaxLength) {
            throw new InvalidArgumentException('The formula must have between 1 and '.self::MaxLength.' characters.');
        }

        $tokens = [];
        $length = strlen($formula);

        for ($i = 0; $i < $length;) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($formula[$i + 1]))) {
                preg_match('/\G\d*\.?\d+/', $formula, $match, 0, $i);
                $tokens[] = ['num', $match[0]];
                $i += strlen($match[0]);
            } elseif (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $formula, $match, 0, $i);
                $tokens[] = ['id', $match[0]];
                $i += strlen($match[0]);
            } elseif (str_contains('+-*/(),', $char)) {
                $tokens[] = ['op', $char];
                $i++;
            } else {
                throw new InvalidArgumentException('Character "'.$char.'" is not allowed in a formula.');
            }

            if (count($tokens) > self::MaxTokens) {
                throw new InvalidArgumentException('The formula is too long.');
            }
        }

        return $tokens;
    }

    /**
     * @return array<int, mixed>
     */
    private function expression(int $depth): array
    {
        $this->guard($depth);
        $left = $this->term($depth);

        while ($this->isOp('+') || $this->isOp('-')) {
            $operator = $this->tokens[$this->position++][1];
            $left = ['bin', $operator, $left, $this->term($depth)];
        }

        return $left;
    }

    /**
     * @return array<int, mixed>
     */
    private function term(int $depth): array
    {
        $left = $this->factor($depth);

        while ($this->isOp('*') || $this->isOp('/')) {
            $operator = $this->tokens[$this->position++][1];
            $left = ['bin', $operator, $left, $this->factor($depth)];
        }

        return $left;
    }

    /**
     * @return array<int, mixed>
     */
    private function factor(int $depth): array
    {
        $this->guard($depth);
        $token = $this->tokens[$this->position] ?? throw new InvalidArgumentException('The formula ends unexpectedly.');

        if ($token[0] === 'op' && $token[1] === '-') {
            $this->position++;

            return ['neg', $this->factor($depth + 1)];
        }

        if ($token[0] === 'num') {
            $this->position++;

            return ['num', $token[1]];
        }

        if ($token[0] === 'op' && $token[1] === '(') {
            $this->position++;
            $inner = $this->expression($depth + 1);
            $this->expect(')');

            return $inner;
        }

        if ($token[0] === 'id') {
            $this->position++;

            if ($this->isOp('(')) {
                return $this->call(strtoupper($token[1]), $depth);
            }

            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $token[1]) !== 1) {
                throw new InvalidArgumentException('Invalid reference "'.$token[1].'".');
            }

            return ['ref', $token[1]];
        }

        throw new InvalidArgumentException('Unexpected token "'.$token[1].'".');
    }

    /**
     * @return array<int, mixed>
     */
    private function call(string $name, int $depth): array
    {
        if (! isset(self::Functions[$name])) {
            throw new InvalidArgumentException('Function "'.$name.'" is not allowed.');
        }

        $this->expect('(');
        $arguments = [$this->expression($depth + 1)];

        while ($this->isOp(',')) {
            $this->position++;
            $arguments[] = $this->expression($depth + 1);
        }

        $this->expect(')');
        [$min, $max] = self::Functions[$name];

        if (count($arguments) < $min || count($arguments) > $max) {
            throw new InvalidArgumentException("Function {$name} takes between {$min} and {$max} arguments.");
        }

        return ['fn', $name, $arguments];
    }

    private function isOp(string $operator): bool
    {
        $token = $this->tokens[$this->position] ?? null;

        return $token !== null && $token[0] === 'op' && $token[1] === $operator;
    }

    private function expect(string $operator): void
    {
        if (! $this->isOp($operator)) {
            throw new InvalidArgumentException('Expected "'.$operator.'".');
        }

        $this->position++;
    }

    private function guard(int $depth): void
    {
        if ($depth > self::MaxDepth) {
            throw new InvalidArgumentException('The formula is nested too deeply.');
        }
    }
}
