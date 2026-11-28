<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\ValueObjects\Locale;

/**
 * Report of the keys that have no text in a locale (nor in its language), optionally restricted to a group.
 */
final readonly class ListMissingKeysQuery
{
    public function __construct(
        public string $tenantId,
        public Locale $locale,
        public ?string $group = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
