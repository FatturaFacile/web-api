<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LookupItem extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'lookup_items';

    protected $fillable = [
        'tenant_id',
        'category_id',
        'code',
        'metadata',
        'sort_order',
        'is_active',
        'is_default',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LookupCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LookupCategory::class, 'category_id');
    }

    /**
     * @return HasMany<LookupItemTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(LookupItemTranslation::class, 'lookup_item_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }
}
