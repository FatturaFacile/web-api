<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Contracts;

/**
 * Read access to single stored translations (no fallback logic: that is the job of TranslationFallbackResolver).
 */
interface TranslationLookup
{
    /**
     * The value stored for exactly this scope, locale and key, or null. A null tenant id addresses the system dictionary.
     */
    public function find(?string $tenantId, string $locale, string $key): ?string;
}
