<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Events;

/**
 * The translations of a locale changed for a tenant (null: the system dictionary). Invalidates the cached dictionaries.
 */
final readonly class TranslationsChanged
{
    public function __construct(public ?string $tenantId, public string $locale) {}
}
