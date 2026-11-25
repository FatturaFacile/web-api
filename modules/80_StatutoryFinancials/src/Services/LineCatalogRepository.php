<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Domain\Statement\LineDefinition;
use Modules\StatutoryFinancials\Models\LineDefinitionRecord;

/**
 * Reads the seeded line catalogue (global rows, overridden by the rows of the active company) and builds the statement trees.
 */
final class LineCatalogRepository
{
    /** @var array<string, array<string, LineDefinition>> */
    private array $cache = [];

    /**
     * Every definition of a statement for a family (the jurisdiction plus the shared "ALL" lines), whatever the scheme.
     *
     * @return array<string, LineDefinition>
     */
    public function definitions(string $family, string $statement, string $variant = 'any'): array
    {
        $key = $family.'|'.$statement.'|'.$variant;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $records = LineDefinitionRecord::query()
            ->whereIn('family', [$family, 'ALL'])
            ->where('statement', $statement)
            ->whereIn('variant', array_unique(['any', $variant]))
            ->orderBy('sort_order')
            ->get();

        $byCode = [];

        foreach ($records as $record) {
            // A company row overrides the global one of the same code.
            if (! isset($byCode[$record->code]) || $record->tenant_id !== null) {
                $byCode[$record->code] = $record->toDefinition();
            }
        }

        uasort($byCode, static fn (LineDefinition $a, LineDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $this->cache[$key] = $byCode;
    }

    public function catalog(AccountingStandard $standard, string $statement, string $variant = 'any'): LineCatalog
    {
        return new LineCatalog($this->definitions($standard->jurisdiction(), $statement, $variant), $standard->scheme());
    }

    /**
     * @return array<string, LineDefinition>
     */
    public function allDefinitions(AccountingStandard $standard): array
    {
        $family = $standard->jurisdiction();

        return $this->definitions($family, 'balance_sheet')
            + $this->definitions($family, 'income_statement', 'total_cost')
            + $this->definitions($family, 'income_statement', 'cost_of_sales')
            + $this->definitions($family, 'cash_flow', 'indirect')
            + $this->definitions($family, 'cash_flow', 'direct');
    }
}
