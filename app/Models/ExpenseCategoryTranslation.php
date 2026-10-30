<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseCategoryTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'expense_category_translations';

    protected $fillable = [
        'tenant_id',
        'expense_category_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
