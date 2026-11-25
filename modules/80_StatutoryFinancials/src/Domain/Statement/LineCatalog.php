<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Statement;

use Modules\StatutoryFinancials\Support\Money;

/**
 * The lines of one statement of one scheme, with the arithmetic of the tree: leaves receive amounts, every other line is the
 * signed sum of its children or the result of its formula. Collapsing to a scheme keeps the abbreviated (Art. 2435-bis) and
 * micro (Art. 2435-ter) statements coarser than the ordinary one without a second catalogue.
 */
final class LineCatalog
{
    /** @var array<string, LineDefinition> */
    private array $lines = [];

    /** @var array<string, list<string>> */
    private array $children = [];

    /**
     * @param  iterable<LineDefinition>  $definitions  All lines of one statement; the scheme filters them.
     */
    public function __construct(iterable $definitions, private readonly string $scheme)
    {
        $all = [];

        foreach ($definitions as $definition) {
            $all[$definition->code] = $definition;
        }

        uasort($all, static fn (LineDefinition $a, LineDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        foreach ($all as $code => $definition) {
            if ($definition->inScheme($scheme)) {
                $this->lines[$code] = $definition;
            }
        }

        foreach ($this->lines as $code => $definition) {
            if ($definition->parentCode !== null && isset($this->lines[$definition->parentCode])) {
                $this->children[$definition->parentCode][] = $code;
            }
        }
    }

    /**
     * @return array<string, LineDefinition>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function has(string $code): bool
    {
        return isset($this->lines[$code]);
    }

    public function get(string $code): ?LineDefinition
    {
        return $this->lines[$code] ?? null;
    }

    public function isLeaf(string $code): bool
    {
        $line = $this->lines[$code] ?? null;

        return $line !== null && $line->formula === null && ($this->children[$code] ?? []) === [];
    }

    /**
     * @return list<string>
     */
    public function leaves(): array
    {
        return array_values(array_filter(array_keys($this->lines), fn (string $code): bool => $this->isLeaf($code)));
    }

    /**
     * The leaf of this scheme that receives the amounts of a line code: the line itself, or its nearest ancestor shown by the scheme.
     * Null when the code (or all its ancestors) is unknown to the statement.
     *
     * @param  array<string, LineDefinition>  $all  Every definition of the statement, whatever the scheme, to climb the ancestry.
     */
    public function resolveLeaf(string $code, array $all): ?string
    {
        $cursor = $code;
        $guard = 0;

        while ($cursor !== null && $guard++ < 12) {
            if (isset($this->lines[$cursor])) {
                return $this->isLeaf($cursor) ? $cursor : null;
            }

            $cursor = isset($all[$cursor]) ? $all[$cursor]->parentCode : null;
        }

        return null;
    }

    /**
     * Amounts of every line from the amounts of the leaves (a leaf without an amount counts as zero).
     *
     * @param  array<string, string>  $leafAmounts
     * @return array<string, string>
     */
    public function totals(array $leafAmounts): array
    {
        $totals = [];

        foreach (array_keys($this->lines) as $code) {
            $this->total($code, $leafAmounts, $totals);
        }

        return $totals;
    }

    /**
     * @param  array<string, string>  $leafAmounts
     * @param  array<string, string>  $totals
     */
    private function total(string $code, array $leafAmounts, array &$totals): string
    {
        if (isset($totals[$code])) {
            return $totals[$code];
        }

        $line = $this->lines[$code];

        if ($line->formula !== null) {
            $sum = '0';

            foreach ($line->formula as [$component, $sign]) {
                if (! isset($this->lines[$component])) {
                    continue;
                }

                $value = $this->total($component, $leafAmounts, $totals);
                $sum = $sign >= 0 ? Money::add($sum, $value) : Money::sub($sum, $value);
            }

            return $totals[$code] = Money::round($sum);
        }

        if (($this->children[$code] ?? []) === []) {
            return $totals[$code] = Money::from($leafAmounts[$code] ?? '0');
        }

        $sum = '0';

        foreach ($this->children[$code] as $child) {
            $value = $this->total($child, $leafAmounts, $totals);
            $sum = $this->lines[$child]->sign >= 0 ? Money::add($sum, $value) : Money::sub($sum, $value);
        }

        return $totals[$code] = Money::round($sum);
    }
}
