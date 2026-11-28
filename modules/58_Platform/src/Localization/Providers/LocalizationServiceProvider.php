<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Localization\Domain\Contracts\TranslationCatalogRepository;
use Modules\Platform\Localization\Domain\Contracts\TranslationLookup;
use Modules\Platform\Localization\Domain\Events\LocaleSettingsChanged;
use Modules\Platform\Localization\Domain\Events\TranslationsChanged;
use Modules\Platform\Localization\Infrastructure\Cache\DictionaryCache;
use Modules\Platform\Localization\Infrastructure\Console\CheckMissingTranslationsCommand;
use Modules\Platform\Localization\Infrastructure\Listeners\FlushDictionaryCache;
use Modules\Platform\Localization\Infrastructure\Persistence\DatabaseTranslationRepository;

/**
 * Localization and i18n engine of the platform: versioned dictionaries, translation overrides per tenant, regional
 * formatting, fiscal localization, master data translations and the `i18n:check-missing` CI gate.
 */
final class LocalizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DictionaryCache::class);
        $this->app->singleton(DatabaseTranslationRepository::class);
        $this->app->bind(TranslationCatalogRepository::class, DatabaseTranslationRepository::class);
        $this->app->bind(TranslationLookup::class, DatabaseTranslationRepository::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../../routes/localization.php');
        });

        Event::listen([TranslationsChanged::class, LocaleSettingsChanged::class], [FlushDictionaryCache::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckMissingTranslationsCommand::class]);
        }
    }
}
