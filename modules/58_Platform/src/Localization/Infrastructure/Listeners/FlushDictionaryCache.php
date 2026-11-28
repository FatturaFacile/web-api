<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Listeners;

use Modules\Platform\Localization\Domain\Events\LocaleSettingsChanged;
use Modules\Platform\Localization\Domain\Events\TranslationsChanged;
use Modules\Platform\Localization\Infrastructure\Cache\DictionaryCache;

/**
 * Invalidates the cached dictionaries by bumping the version of the scope that changed.
 */
final class FlushDictionaryCache
{
    public function __construct(private readonly DictionaryCache $cache) {}

    public function handle(TranslationsChanged|LocaleSettingsChanged $event): void
    {
        if ($event->tenantId === null) {
            $this->cache->flushSystem();

            return;
        }

        $this->cache->flushTenant($event->tenantId);
    }
}
