<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Contracts;

use Modules\Platform\Localization\Domain\Entities\TranslationCatalog;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

interface TranslationCatalogRepository
{
    /**
     * The catalog of a scope restricted to the given keys (plus the locked system keys among them).
     *
     * @param  list<TranslationKey>  $keys
     */
    public function load(?string $tenantId, Locale $locale, array $keys): TranslationCatalog;

    /**
     * Persists the entries changed since the catalog was loaded and publishes its domain events.
     */
    public function save(TranslationCatalog $catalog): void;
}
