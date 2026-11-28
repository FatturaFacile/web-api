<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\ValueObjects\Locale;

/**
 * The translation editor list: the keys of a locale with their effective value, filtered and paginated.
 */
final readonly class ListTranslationsQuery
{
    public function __construct(
        public string $tenantId,
        public Locale $locale,
        public ?string $group = null,
        public bool $onlyMissing = false,
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
