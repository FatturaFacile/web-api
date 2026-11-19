<?php

declare(strict_types=1);

namespace Modules\Analytics\Calculation;

use InvalidArgumentException;

/**
 * The calculated fields of a report: validated declarations (`key`, `label`, `formula`, `format`) and their evaluation
 * over aggregated rows with bcmath. A missing input or a division by zero gives null, never an error, so one empty cell
 * does not break a report. Evaluation order follows the declaration, so a field may read the ones declared before it.
 */
final class CalculatedFields
{
    public const int MaxFields = 10;

    public const int Scale = 6;

    public const array Formats = ['currency', 'number', 'percentage', 'integer'];

    public function __construct(private readonly FormulaParser $parser = new FormulaParser) {}

    /**
     * Validates the declarations against the measure keys available in the report.
     *
     * @param  list<string>  $measureKeys  selected measures of the dataset
     * @return list<array{key: string, label: string, formula: string, format: string}>
     *
     * @throws InvalidArgumentException with the field index in the message when something is wrong
     */
    public function validate(mixed $raw, array $measureKeys, array $reservedKeys = []): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::MaxFields) {
            throw new InvalidArgumentException('calculated_fields must be a list of at most '.self::MaxFields.' items.');
        }

        $available = $measureKeys;
        $fields = [];

        foreach ($raw as $index => $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            $formula = is_array($field) ? ($field['formula'] ?? null) : null;

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1 || in_array($key, [...$available, ...$reservedKeys], true)) {
                throw new InvalidArgumentException("calculated_fields.{$index}.key is invalid or already used.");
            }

            if (! is_string($formula)) {
                throw new InvalidArgumentException("calculated_fields.{$index}.formula is required.");
            }

            try {
                $references = $this->parser->references($formula);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException("calculated_fields.{$index}.formula: ".$exception->getMessage(), previous: $exception);
            }

            foreach ($references as $reference) {
                if (! in_array($reference, $available, true)) {
                    throw new InvalidArgumentException("calculated_fields.{$index}.formula: unknown measure \"{$reference}\".");
                }
            }

            $format = (string) ($field['format'] ?? 'number');

            if (! in_array($format, self::Formats, true)) {
                throw new InvalidArgumentException("calculated_fields.{$index}.format is invalid.");
            }

            $fields[] = ['key' => $key, 'label' => mb_substr((string) ($field['label'] ?? $key), 0, 120), 'formula' => $formula, 'format' => $format];
            $available[] = $key;
        }

        return $fields;
    }

    /**
     * Adds the calculated values to one row.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array{key: string, label: string, formula: string, format: string}>  $fields
     * @return array<string, mixed>
     */
    public function apply(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            $row[$field['key']] = $this->evaluate($this->parser->parse($field['formula']), $row);
        }

        return $row;
    }

    /**
     * @param  array<int, mixed>  $node
     * @param  array<string, mixed>  $row
     */
    public function evaluate(array $node, array $row): ?string
    {
        switch ($node[0]) {
            case 'num':
                return $node[1];
            case 'ref':
                $value = $row[$node[1]] ?? null;

                return is_numeric($value) ? (string) $value : null;
            case 'neg':
                $value = $this->evaluate($node[1], $row);

                return $value === null ? null : bcmul($value, '-1', self::Scale);
            case 'bin':
                $left = $this->evaluate($node[2], $row);
                $right = $this->evaluate($node[3], $row);

                if ($left === null || $right === null) {
                    return null;
                }

                return match ($node[1]) {
                    '+' => bcadd($left, $right, self::Scale),
                    '-' => bcsub($left, $right, self::Scale),
                    '*' => bcmul($left, $right, self::Scale),
                    default => bccomp($right, '0', self::Scale) === 0 ? null : bcdiv($left, $right, self::Scale),
                };
            default:
                return $this->call($node[1], array_map(fn (array $argument): ?string => $this->evaluate($argument, $row), $node[2]));
        }
    }

    /**
     * @param  list<?string>  $arguments
     */
    private function call(string $name, array $arguments): ?string
    {
        switch ($name) {
            case 'ABS':
                return $arguments[0] === null ? null : ltrim($arguments[0], '-');
            case 'ROUND':
                if ($arguments[0] === null) {
                    return null;
                }

                $digits = max(0, min(self::Scale, (int) ($arguments[1] ?? 0)));
                $shift = bcpow('10', (string) $digits, 0);
                $scaled = bcmul($arguments[0], $shift, self::Scale);
                $half = bccomp($scaled, '0', self::Scale) >= 0 ? '0.5' : '-0.5';
                $rounded = bcadd($scaled, $half, 0);

                return bcdiv($rounded, $shift, $digits);
            case 'COALESCE':
                foreach ($arguments as $argument) {
                    if ($argument !== null) {
                        return $argument;
                    }
                }

                return null;
            default:
                return $arguments[0] !== null && $arguments[1] !== null && bccomp($arguments[0], $arguments[1], self::Scale) === 0 ? null : $arguments[0];
        }
    }
}
