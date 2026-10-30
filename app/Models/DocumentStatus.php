<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentStatus extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $table = 'document_statuses';

    protected $fillable = [
        'tenant_id',
        'scope',
        'code',
        'color_badge',
        'sort_order',
        'is_terminal',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_terminal' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DocumentStatusTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(DocumentStatusTranslation::class, 'document_status_id');
    }

    protected function getTranslationModelClass(): string
    {
        return DocumentStatusTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'document_status_id';
    }
}
