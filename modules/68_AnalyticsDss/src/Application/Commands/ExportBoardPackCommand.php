<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Commands;

use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;

/**
 * Generates the localized Executive Board Briefing Pack (PDF or XLSX) for the filter.
 */
final readonly class ExportBoardPackCommand
{
    public function __construct(public DssFilter $filter, public string $format = 'pdf', public string $locale = 'en') {}
}
