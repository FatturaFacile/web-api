<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Commands;

use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

/**
 * Creates or changes the tenant override of one translation.
 */
final readonly class UpsertTranslationCommand
{
    public function __construct(
        public string $tenantId,
        public Locale $locale,
        public TranslationKey $key,
        public string $value,
    ) {}
}
