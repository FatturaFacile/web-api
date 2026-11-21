<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\ValueObjects;

/**
 * One attribute comparison evaluated without eval(): `attribute operator value`. Only allowlisted attributes and
 * operators are accepted, and a missing attribute on the evaluated context never matches (deny-safe).
 */
final readonly class AbacCondition
{
    public const ATTRIBUTES = [
        'contact.owner_id', 'contact.region', 'document.total', 'company_id',
        'context.ip', 'context.hour', 'context.weekday',
    ];

    public const OPERATORS = ['==', '!=', '<', '<=', '>', '>=', 'in', 'not_in'];

    public function __construct(
        public string $attribute,
        public string $operator,
        public mixed $value,
    ) {}

    public static function isDefinitionValid(string $attribute, string $operator): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true) && in_array($operator, self::OPERATORS, true);
    }

    /**
     * @param  array<string, mixed>  $context  flat map keyed by attribute name
     */
    public function matches(array $context): bool
    {
        if (! self::isDefinitionValid($this->attribute, $this->operator)) {
            return false;
        }

        if (! array_key_exists($this->attribute, $context) || $context[$this->attribute] === null) {
            return false;
        }

        $actual = $context[$this->attribute];

        return match ($this->operator) {
            '==' => $this->loose($actual, $this->value),
            '!=' => ! $this->loose($actual, $this->value),
            '<' => is_numeric($actual) && is_numeric($this->value) && $actual < $this->value,
            '<=' => is_numeric($actual) && is_numeric($this->value) && $actual <= $this->value,
            '>' => is_numeric($actual) && is_numeric($this->value) && $actual > $this->value,
            '>=' => is_numeric($actual) && is_numeric($this->value) && $actual >= $this->value,
            'in' => is_array($this->value) && $this->inList($actual, $this->value),
            'not_in' => is_array($this->value) && ! $this->inList($actual, $this->value),
            default => false,
        };
    }

    private function loose(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return is_scalar($a) && is_scalar($b) && (string) $a === (string) $b;
    }

    /**
     * @param  array<mixed>  $list
     */
    private function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $item) {
            if ($this->loose($actual, $item)) {
                return true;
            }
        }

        return false;
    }
}
