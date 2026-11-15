<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use InvalidArgumentException;

/**
 * Parses and evaluates the formula of a calculated measure: numbers, the keys of measures, the four operations, unary
 * minus and parentheses. Nothing else is accepted (no functions, no strings, no SQL), so a formula can never reach the
 * database or the interpreter. The arithmetic uses bcmath on decimal strings; a missing input or a division by zero gives
 * null instead of an error, because one empty cell must not break a whole report.
 *
 * The parsed tree is an array: `['num', string]`, `['ref', key]`, `['neg', node]` or `[op, left, right]` with op one of
 * `+ - * /`.
 */
final class FormulaEvaluator
{
    public const int MaxLength = 500;

    public const int MaxTokens = 120;

    private const int InternalScale = 10;

    /**
     * The tree of a formula.
     *
     * @return array<int, mixed>
     *
     * @throws InvalidArgumentException with a translated message when the formula is not valid
     */
    public function parse(string $formula): array
    {
        $tokens = $this->tokenize($formula);
        $position = 0;
        $tree = $this->expression($tokens, $position);

        if ($position !== count($tokens)) {
            throw new InvalidArgumentException(__('bi_errors.formula.unexpected', ['token' => (string) $tokens[$position][1]]));
        }

        return $tree;
    }

    /**
     * The measure keys a formula reads, in order of first appearance.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the formula is not valid
     */
    public function references(string $formula): array
    {
        $references = [];
        $this->collect($this->parse($formula), $references);

        return array_values(array_unique($references));
    }

    /**
     * Computes the formula for one row.
     *
     * @param  array<string, string|int|null>  $values  figures of the base measures by key
     */
    public function evaluate(string $formula, array $values, int $scale): ?string
    {
        return $this->evaluateTree($this->parse($formula), $values, $scale);
    }

    /**
     * Computes an already parsed formula, so a report parses it once for all its rows.
     *
     * @param  array<int, mixed>  $tree
     * @param  array<string, string|int|null>  $values
     */
    public function evaluateTree(array $tree, array $values, int $scale): ?string
    {
        $result = $this->compute($tree, $values);

        return $result === null ? null : $this->round($result, $scale);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function tokenize(string $formula): array
    {
        if (trim($formula) === '' || strlen($formula) > self::MaxLength) {
            throw new InvalidArgumentException(__('bi_errors.formula.length', ['max' => self::MaxLength]));
        }

        $tokens = [];
        $length = strlen($formula);

        for ($i = 0; $i < $length;) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (strpbrk($char, '+-*/()') !== false) {
                $tokens[] = ['op', $char];
                $i++;
            } elseif (ctype_digit($char) || $char === '.') {
                $start = $i;

                while ($i < $length && (ctype_digit($formula[$i]) || $formula[$i] === '.')) {
                    $i++;
                }

                $number = substr($formula, $start, $i - $start);

                if (preg_match('/^(\d+(\.\d{1,8})?|\.\d{1,8})$/', $number) !== 1) {
                    throw new InvalidArgumentException(__('bi_errors.formula.number', ['token' => $number]));
                }

                $tokens[] = ['num', $number[0] === '.' ? '0'.$number : $number];
            } elseif (ctype_alpha($char) || $char === '_') {
                $start = $i;

                while ($i < $length && (ctype_alnum($formula[$i]) || $formula[$i] === '_')) {
                    $i++;
                }

                $tokens[] = ['ref', substr($formula, $start, $i - $start)];
            } else {
                throw new InvalidArgumentException(__('bi_errors.formula.character', ['character' => $char]));
            }

            if (count($tokens) > self::MaxTokens) {
                throw new InvalidArgumentException(__('bi_errors.formula.length', ['max' => self::MaxLength]));
            }
        }

        return $tokens;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private function expression(array $tokens, int &$position, int $depth = 0): array
    {
        $this->guardDepth($depth);
        $left = $this->term($tokens, $position, $depth);

        while (isset($tokens[$position]) && $tokens[$position][0] === 'op' && in_array($tokens[$position][1], ['+', '-'], true)) {
            $operator = $tokens[$position++][1];
            $left = [$operator, $left, $this->term($tokens, $position, $depth)];
        }

        return $left;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private function term(array $tokens, int &$position, int $depth): array
    {
        $left = $this->factor($tokens, $position, $depth);

        while (isset($tokens[$position]) && $tokens[$position][0] === 'op' && in_array($tokens[$position][1], ['*', '/'], true)) {
            $operator = $tokens[$position++][1];
            $left = [$operator, $left, $this->factor($tokens, $position, $depth)];
        }

        return $left;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private function factor(array $tokens, int &$position, int $depth): array
    {
        $this->guardDepth($depth);
        $token = $tokens[$position] ?? null;

        if ($token === null) {
            throw new InvalidArgumentException(__('bi_errors.formula.incomplete'));
        }

        $position++;

        if ($token[0] === 'num' || $token[0] === 'ref') {
            return [$token[0], $token[1]];
        }

        if ($token[1] === '-') {
            return ['neg', $this->factor($tokens, $position, $depth + 1)];
        }

        if ($token[1] === '(') {
            $inner = $this->expression($tokens, $position, $depth + 1);

            if (($tokens[$position][1] ?? null) !== ')') {
                throw new InvalidArgumentException(__('bi_errors.formula.parenthesis'));
            }

            $position++;

            return $inner;
        }

        throw new InvalidArgumentException(__('bi_errors.formula.unexpected', ['token' => $token[1]]));
    }

    private function guardDepth(int $depth): void
    {
        if ($depth > 20) {
            throw new InvalidArgumentException(__('bi_errors.formula.too_deep'));
        }
    }

    /**
     * @param  array<int, mixed>  $node
     * @param  list<string>  $references
     */
    private function collect(array $node, array &$references): void
    {
        match ($node[0]) {
            'ref' => $references[] = $node[1],
            'neg' => $this->collect($node[1], $references),
            'num' => null,
            default => [$this->collect($node[1], $references), $this->collect($node[2], $references)],
        };
    }

    /**
     * @param  array<int, mixed>  $node
     * @param  array<string, string|int|null>  $values
     */
    private function compute(array $node, array $values): ?string
    {
        switch ($node[0]) {
            case 'num':
                return $node[1];
            case 'ref':
                $value = $values[$node[1]] ?? null;

                return $value === null ? null : (string) $value;
            case 'neg':
                $inner = $this->compute($node[1], $values);

                return $inner === null ? null : bcsub('0', $inner, self::InternalScale);
        }

        $left = $this->compute($node[1], $values);
        $right = $this->compute($node[2], $values);

        if ($left === null || $right === null) {
            return null;
        }

        return match ($node[0]) {
            '+' => bcadd($left, $right, self::InternalScale),
            '-' => bcsub($left, $right, self::InternalScale),
            '*' => bcmul($left, $right, self::InternalScale),
            default => bccomp($right, '0', self::InternalScale) === 0 ? null : bcdiv($left, $right, self::InternalScale),
        };
    }

    /**
     * Rounds half away from zero to the scale of the measure.
     */
    private function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', self::InternalScale) >= 0 ? bcadd($value, $half, self::InternalScale) : bcsub($value, $half, self::InternalScale);

        return bcadd($adjusted, '0', $scale);
    }
}
