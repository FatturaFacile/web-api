<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\ValueObjects\Locale;

final readonly class GetDictionaryQuery
{
    public function __construct(public ?string $tenantId, public Locale $locale) {}
}
