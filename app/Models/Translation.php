<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TranslationFactory;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    /** @use HasFactory<TranslationFactory> */
    use AllowsGlobalRecords, BelongsToTenant, HasFactory;

    /**
     * Languages the platform ships translations for; also the only values allowed in public cache keys.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_LOCALES = ['it', 'de', 'fr', 'en'];

    public const DEFAULT_LOCALE = 'it';

    /**
     * Regional variants and the language they derive from: the dictionary of a variant is that of its language with
     * the regional spelling applied (see RegionalTranslations) and the tenant overrides saved under the variant.
     *
     * @var array<string, string>
     */
    public const REGIONAL_LOCALES = ['it-CH' => 'it', 'de-CH' => 'de', 'fr-CH' => 'fr', 'it-IT' => 'it'];

    protected $fillable = [
        'tenant_id',
        'locale',
        'group',
        'key',
        'value',
    ];

    /**
     * Restrict a client-supplied locale to the supported languages, falling back to the default.
     * Public endpoints build permanent cache keys from the locale, so arbitrary strings must never reach them.
     */
    public static function normalizeLocale(?string $locale): string
    {
        $normalized = strtolower(trim((string) $locale));

        foreach (array_keys(self::REGIONAL_LOCALES) as $regional) {
            if (strtolower($regional) === $normalized) {
                return $regional;
            }
        }

        return in_array($normalized, self::SUPPORTED_LOCALES, true) ? $normalized : self::DEFAULT_LOCALE;
    }

    /**
     * The language a locale derives from: itself for a language, the parent language for a regional variant.
     */
    public static function baseLocale(string $locale): string
    {
        return self::REGIONAL_LOCALES[$locale] ?? $locale;
    }

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
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }
}
