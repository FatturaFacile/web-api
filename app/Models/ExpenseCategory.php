<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'expense_categories';

    protected $fillable = [
        'code',
        'requires_receipt',
        'suggested_account_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_receipt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ExpenseCategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ExpenseCategoryTranslation::class, 'expense_category_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ExpenseCategoryTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'expense_category_id';
    }
}
