<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\App;

trait HasTranslations
{
    /**
     * Get all translations for this model.
     *
     * @return HasMany<covariant Model, $this>
     */
    abstract public function translations(): HasMany;

    /**
     * Get the translation for the current active application locale.
     *
     * @return HasOne<Model, $this>
     */
    public function currentTranslation(): HasOne
    {
        $locale = App::getLocale();

        return $this->translationForLocale($locale);
    }

    /**
     * Get the translation for a specific locale.
     *
     * @return HasOne<Model, $this>
     */
    public function translationForLocale(string $locale): HasOne
    {
        return $this->hasOne($this->getTranslationModelClass(), $this->getTranslationForeignKey())
            ->where('locale', $locale);
    }

    /**
     * Eager-loads only the translations of the requested locale and of the company fallback locale: one extra query for
     * the whole page of records and never the rows of the other languages (no N+1, no over-fetching).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithTranslation(Builder $query, ?string $locale = null, ?string $companyLocale = null): Builder
    {
        $locales = array_values(array_unique(array_filter([
            $locale ?? App::getLocale(),
            $companyLocale ?? (string) config('app.fallback_locale', 'en'),
        ])));

        return $query->with(['translations' => static fn ($relation) => $relation->whereIn('locale', $locales)]);
    }

    /**
     * Translated attribute with the fallback requested locale, then company locale, then the first available value.
     * A regional locale (de-CH) falls back on its language (de) before the company locale.
     */
    public function getTranslation(string $attribute, ?string $locale = null, ?string $companyLocale = null): ?string
    {
        $targetLocale = $locale ?? App::getLocale();
        $fallbackLocale = $companyLocale ?? (string) config('app.fallback_locale', 'en');
        $language = strtolower(explode('-', $targetLocale)[0]);

        $candidates = array_values(array_unique([$targetLocale, $language, $fallbackLocale]));

        foreach ($candidates as $candidate) {
            foreach ($this->translations as $translation) {
                $value = $translation->locale === $candidate ? ($translation->{$attribute} ?? null) : null;

                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            }
        }

        foreach ($this->translations as $translation) {
            $value = $translation->{$attribute} ?? null;

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    abstract protected function getTranslationModelClass(): string;

    abstract protected function getTranslationForeignKey(): string;
}
