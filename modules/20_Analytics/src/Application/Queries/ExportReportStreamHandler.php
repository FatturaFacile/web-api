<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use Modules\Analytics\Pivot\DesignedReportPlan;
use Modules\Analytics\Services\ReportExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Validates the definition, records the export (audit entry and execution log) and streams the file straight to the HTTP
 * response: CSV, XLSX, PDF or JSON, flat or pivot, in the locale asked for.
 */
final readonly class ExportReportStreamHandler
{
    public function __construct(private DesignedReportPlan $plans, private ReportExporter $exporter) {}

    public function handle(ExportReportStreamQuery $query): StreamedResponse
    {
        $plan = $this->plans->build($query->definition, $query->user, 'spec.');
        $prepared = $plan['pivot'] === null
            ? $this->exporter->prepare($plan['spec'], $query->user, $query->format, $query->report, locale: $query->locale)
            : $this->exporter->preparePivot($plan['spec'], $plan['pivot'], $plan['calculated'], $query->user, $query->format, $query->report, locale: $query->locale);

        return $this->exporter->response($prepared);
    }
}
