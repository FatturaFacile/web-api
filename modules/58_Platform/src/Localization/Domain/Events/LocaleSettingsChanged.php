<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Events;

/**
 * The locale settings (active languages, default language, number and date formats) of a tenant changed.
 */
final readonly class LocaleSettingsChanged
{
    public function __construct(public string $tenantId) {}
}
