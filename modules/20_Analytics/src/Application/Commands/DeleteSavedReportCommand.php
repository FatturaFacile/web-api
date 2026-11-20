<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use Modules\Analytics\Models\DesignedReport;

/**
 * Deletes a saved report together with its schedules.
 */
final readonly class DeleteSavedReportCommand
{
    public function __construct(public DesignedReport $report) {}
}
