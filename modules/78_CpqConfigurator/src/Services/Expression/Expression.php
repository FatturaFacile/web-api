<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services\Expression;

/**
 * A parsed expression: an immutable syntax tree plus the variable names it reads.
 *
 * @phpstan-type Node array<string, mixed>
 */
final readonly class Expression
{
    /**
     * @param  array<string, mixed>  $root
     */
    public function __construct(public string $source, public array $root) {}

    /**
     * Names of the variables the expression reads, sorted, without duplicates ("material", "attr.material.density").
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $names = [];
        $this->collect($this->root, $names);
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $names
     */
    private function collect(array $node, array &$names): void
    {
        if (($node['t'] ?? null) === 'var') {
            $names[(string) $node['n']] = true;

            return;
        }

        foreach (['e', 'l', 'r'] as $child) {
            if (isset($node[$child]) && is_array($node[$child])) {
                $this->collect($node[$child], $names);
            }
        }

        foreach (['items', 'args'] as $children) {
            foreach ($node[$children] ?? [] as $child) {
                $this->collect($child, $names);
            }
        }
    }
}
