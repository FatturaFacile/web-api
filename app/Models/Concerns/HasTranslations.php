<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\App;

trait HasTranslations
{
    /**
     * Get all translations for this model.
     *
     * @return HasMany<Model, $this>
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
     * Get translation attribute helper.
     */
    public function getTranslation(string $attribute, ?string $locale = null): ?string
    {
        $targetLocale = $locale ?? App::getLocale();
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        $translation = $this->translations->firstWhere('locale', $targetLocale)
            ?? $this->translations->firstWhere('locale', $fallbackLocale)
            ?? $this->translations->first();

        return $translation ? ($translation->{$attribute} ?? null) : null;
    }

    abstract protected function getTranslationModelClass(): string;

    abstract protected function getTranslationForeignKey(): string;
}
