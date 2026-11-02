<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    /** @use HasFactory<TranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'locale',
        'group',
        'key',
        'value',
    ];

    /**
     * Scope query to a specific tenant ID with fallback to global (null) translations.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeForTenant(Builder $query, ?string $tenantId = null): Builder
    {
        return $query->where(function (Builder $q) use ($tenantId): void {
            if (! empty($tenantId)) {
                $q->where('tenant_id', $tenantId)
                    ->orWhereNull('tenant_id');
            } else {
                $q->whereNull('tenant_id');
            }
        });
    }

    /**
     * Indicate that translations is a shared reference dataset with null fallbacks.
     */
    public function isSharedLookup(): bool
    {
        return true;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }
}
