<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\ValueObjects\Locale;

final readonly class ExportTranslationsQuery
{
    /**
     * @param  list<Locale>  $locales
     * @param  'xlsx'|'csv'  $format
     */
    public function __construct(public string $tenantId, public array $locales, public string $format = 'xlsx') {}
}
