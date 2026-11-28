<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable text block of the documents (footers, legal clauses), translated per locale.
 */
class StandardText extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory, HasTranslations, HasUuids, SoftDeletes;

    protected $table = 'standard_texts';

    protected $fillable = [
        'tenant_id',
        'code',
        'title',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<StandardTextTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(StandardTextTranslation::class, 'standard_text_id');
    }

    protected function getTranslationModelClass(): string
    {
        return StandardTextTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'standard_text_id';
    }
}
