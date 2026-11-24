<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expense paid with the 5x1000, classified by category.
 *
 * @property string $amount
 * @property-read ExpenseCategory $category
 */
final class FivePerMilleExpense extends AssociationRecord
{
    protected $table = 'assoc_five_per_mille_expenses';

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}
