<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Security;

use App\Models\User;
use Modules\Analytics\Semantic\Dataset;

/**
 * The row-level security rules of the application. Other modules register theirs in their service provider; the
 * business intelligence queries ask the registry for the extra filters of the user before they run.
 */
final class RowLevelSecurityRegistry
{
    /**
     * @var list<RowLevelSecurityRule>
     */
    private array $rules = [];

    public function register(RowLevelSecurityRule $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * The mandatory filters of every rule that applies to the dataset.
     *
     * @return list<array{field: string, operator: string, value?: mixed}>
     */
    public function filtersFor(User $user, Dataset $dataset): array
    {
        $filters = [];

        foreach ($this->rules as $rule) {
            if ($rule->appliesTo($dataset)) {
                array_push($filters, ...$rule->filtersFor($user, $dataset));
            }
        }

        return $filters;
    }
}
