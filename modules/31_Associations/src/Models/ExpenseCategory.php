<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Database\Factories\AssociationsExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * A category used to classify expenses in a report, such as the use of the 5x1000. Categories are configured by the
 * company: the code does not presume the headings of any official form.
 */
#[UseFactory(AssociationsExpenseCategoryFactory::class)]
final class ExpenseCategory extends AssociationRecord
{
    public const string FivePerMille = 'five_per_mille';

    protected $table = 'assoc_expense_categories';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
