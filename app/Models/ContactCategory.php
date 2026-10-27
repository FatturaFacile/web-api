<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactCategory extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'contact_categories';

    protected $fillable = [
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ContactCategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ContactCategoryTranslation::class, 'contact_category_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ContactCategoryTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'contact_category_id';
    }
}
