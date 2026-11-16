<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Security;

use App\Models\User;
use Modules\Analytics\Semantic\Dataset;

/**
 * Hook of the row-level security of the business intelligence queries. Every query is already limited to the active
 * company by the semantic layer; a rule narrows it further for a user (for example a sales agent who only sees the
 * documents of their own customers) by returning extra filters on dimensions of the dataset. The filters are mandatory:
 * they are merged into the specification of every dashboard, widget, pivot and snapshot of the user and go through the
 * same validation as the filters of the designer, so a rule can never open more than the dataset declares.
 */
interface RowLevelSecurityRule
{
    /**
     * Whether the rule has something to say about the dataset.
     */
    public function appliesTo(Dataset $dataset): bool;

    /**
     * The mandatory filters for the user on the dataset.
     *
     * @return list<array{field: string, operator: string, value?: mixed}>
     */
    public function filtersFor(User $user, Dataset $dataset): array;
}
