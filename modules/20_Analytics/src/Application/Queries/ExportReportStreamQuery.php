<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use App\Models\User;
use Modules\Analytics\Models\DesignedReport;

/**
 * Asks for a report as a downloadable stream in a format and locale: either a saved report or a definition being edited.
 */
final readonly class ExportReportStreamQuery
{
    /**
     * @param  array<string, mixed>  $definition  dataset, spec, pivot, calculated_fields, conditional_formatting, chart_config
     */
    public function __construct(public User $user, public array $definition, public string $format, public ?string $locale = null, public ?DesignedReport $report = null) {}
}
